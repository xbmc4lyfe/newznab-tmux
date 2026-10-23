//! IMDb HTML and JSON-LD scraping helpers for `libnntmux_pipeline`.
//!
//! These functions let `ImdbScraper::parseTitleHtml` pull JSON-LD blocks and title-detail languages
//! out of a 300-600 KB title page with a single pass over the raw bytes, so the full
//! `HtmlDomParser` DOM is only built when a fallback selector is actually needed.
use crate::OutCursor;
use std::slice;

/// Section tag for a JSON-LD script body (`<script type="application/ld+json">…</script>`).
const TAG_JSONLD: u8 = b'J';
/// Section tag for one language link text from `data-testid="title-details-languages"`.
const TAG_LANGUAGE: u8 = b'L';
/// Section tag meaning the language markup was too irregular to extract safely; use the DOM.
const TAG_LANGUAGE_DOM: u8 = b'D';
/// Returned when the input cannot be handled with exact PHP parity; the caller must fall back.
const UNSUPPORTED: isize = -1;
const MAX_LANGUAGES: usize = 64;
const LANGUAGE_MARKER: &[u8] = b"title-details-languages";

#[inline]
fn eq_ci(hay: &[u8], needle_lower: &[u8]) -> bool {
    hay.len() >= needle_lower.len()
        && hay
            .iter()
            .zip(needle_lower)
            .all(|(h, n)| h.to_ascii_lowercase() == *n)
}

/// First index `>= from` holding `lo` or `hi`.
#[inline]
fn find_byte2(hay: &[u8], from: usize, lo: u8, hi: u8) -> Option<usize> {
    #[cfg(target_arch = "aarch64")]
    {
        find_byte2_neon(hay, from, lo, hi)
    }
    #[cfg(not(target_arch = "aarch64"))]
    {
        find_byte2_portable(hay, from, lo, hi)
    }
}

/// Portable version, chunked so LLVM can vectorize the inner comparison.
#[cfg_attr(target_arch = "aarch64", allow(dead_code))]
fn find_byte2_portable(hay: &[u8], from: usize, lo: u8, hi: u8) -> Option<usize> {
    if from >= hay.len() {
        return None;
    }
    let tail = &hay[from..];
    let mut offset = 0;
    let (chunks, remainder) = tail.as_chunks::<32>();
    for chunk in chunks {
        if chunk.iter().fold(false, |a, &b| a | (b == lo) | (b == hi)) {
            let at = chunk.iter().position(|&b| b == lo || b == hi)?;
            return Some(from + offset + at);
        }
        offset += 32;
    }
    remainder
        .iter()
        .position(|&b| b == lo || b == hi)
        .map(|at| from + offset + at)
}

/// NEON version: 32 bytes per iteration with a single horizontal reduction.
#[cfg(target_arch = "aarch64")]
fn find_byte2_neon(hay: &[u8], from: usize, lo: u8, hi: u8) -> Option<usize> {
    use std::arch::aarch64::{vceqq_u8, vdupq_n_u8, vld1q_u8, vmaxvq_u8, vorrq_u8};

    let n = hay.len();
    let mut i = from;
    // SAFETY: every load reads 16 bytes at `i` or `i + 16` with `i + 32 <= n`; NEON is baseline on aarch64.
    unsafe {
        let vlo = vdupq_n_u8(lo);
        let vhi = vdupq_n_u8(hi);
        let base = hay.as_ptr();
        while i + 32 <= n {
            let a = vld1q_u8(base.add(i));
            let b = vld1q_u8(base.add(i + 16));
            let m = vorrq_u8(
                vorrq_u8(vceqq_u8(a, vlo), vceqq_u8(a, vhi)),
                vorrq_u8(vceqq_u8(b, vlo), vceqq_u8(b, vhi)),
            );
            if vmaxvq_u8(m) != 0 {
                break;
            }
            i += 32;
        }
    }
    hay.get(i..)?
        .iter()
        .position(|&b| b == lo || b == hi)
        .map(|at| i + at)
}

#[inline]
fn find_byte(hay: &[u8], from: usize, needle: u8) -> Option<usize> {
    find_byte2(hay, from, needle, needle)
}

/// ASCII case-insensitive substring search; `needle_lower` must be lowercase and `anchor` selects the
/// needle byte used to skip through the haystack (pick a rare one).
fn find_ci(hay: &[u8], from: usize, needle_lower: &[u8], anchor: usize) -> Option<usize> {
    let a = needle_lower[anchor];
    let upper = a.to_ascii_uppercase();
    let mut cursor = from + anchor;
    while let Some(p) = find_byte2(hay, cursor, a, upper) {
        let start = p - anchor;
        if eq_ci(&hay[start..], needle_lower) {
            return Some(start);
        }
        cursor = p + 1;
    }
    None
}

#[inline]
fn php_trim(mut s: &[u8]) -> &[u8] {
    const WS: &[u8] = b" \t\n\r\0\x0B";
    while let [first, rest @ ..] = s {
        if WS.contains(first) {
            s = rest;
        } else {
            break;
        }
    }
    while let [rest @ .., last] = s {
        if WS.contains(last) {
            s = rest;
        } else {
            break;
        }
    }
    s
}

/// Mirrors `Str::contains($body, ['window.awsWafCookieDomainList', 'window.gokuProps', 'challenge.js',
/// 'captcha', 'awswaf'], true)`; the first marker contains `awswaf`, so four needles suffice.
/// Returns `None` when the PHP `mb_strtolower` folding could differ from ASCII folding.
fn is_waf_body(html: &[u8]) -> Option<bool> {
    const NEEDLES: [(&[u8], usize); 4] = [
        (b"awswaf", 5),
        (b"captcha", 2),
        (b"challenge.js", 10),
        (b"window.gokuprops", 7),
    ];
    if NEEDLES
        .iter()
        .any(|(needle, anchor)| find_ci(html, 0, needle, *anchor).is_some())
    {
        return Some(true);
    }
    // U+212A KELVIN SIGN lowercases to ASCII `k` under mb_strtolower, which could complete `gokuProps`.
    let mut at = 0;
    while let Some(p) = find_byte(html, at, 0xE2) {
        if html.get(p + 1..p + 3) == Some(&[0x84, 0xAA]) {
            return None;
        }
        at = p + 1;
    }
    Some(false)
}

/// Returns 1 when `html` carries an AWS WAF / captcha challenge marker, 0 when it does not, and -1 when the
/// caller must use the PHP implementation for exact parity.
///
/// # Safety
/// `html` must point to `html_len` readable bytes (or be null with `html_len == 0`).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_imdb_is_waf_response(html: *const u8, html_len: usize) -> i32 {
    let html = unsafe { bytes(html, html_len) };
    match is_waf_body(html) {
        Some(true) => 1,
        Some(false) => 0,
        None => -1,
    }
}

#[inline]
unsafe fn bytes<'a>(ptr: *const u8, len: usize) -> &'a [u8] {
    if ptr.is_null() || len == 0 {
        &[]
    } else {
        unsafe { slice::from_raw_parts(ptr, len) }
    }
}

/// Emits every JSON-LD body exactly as `preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is')`
/// captures it. Returns `false` when a body contains a NUL (unrepresentable in the framing).
///
/// Rather than visiting every `<script`, it jumps between `application/ld+json` occurrences (anchored on the
/// rare `+`) and recovers the leftmost `<script` the regex would have started the match at.
fn emit_jsonld(html: &[u8], out: &mut OutCursor<'_>) -> bool {
    const NAME: &[u8] = b"application/ld+json";
    // `type="` precedes the name and a closing quote follows it.
    const LEAD: usize = 6;
    let mut pos = 0; // end of the previous match: the regex resumes here
    let mut at = 0;
    while let Some(p) = find_ci(html, at, NAME, 14) {
        at = p + 1;
        let after = p + NAME.len();
        if p < pos + LEAD
            || !matches!(html[p - 1], b'"' | b'\'')
            || !eq_ci(&html[p - LEAD..p - 1], b"type=")
            || !matches!(html.get(after), Some(b'"' | b'\''))
        {
            continue;
        }
        let type_start = p - LEAD;
        // `<script` and the attribute must share one tag: no `>` between them.
        let lower = html[pos..type_start]
            .iter()
            .rposition(|&b| b == b'>')
            .map_or(pos, |gt| pos + gt + 1);
        let Some(script) = find_ci(&html[..type_start], lower, b"<script", 0) else {
            continue;
        };
        debug_assert!(script + 7 <= type_start);
        let Some(gt) = find_byte(html, after + 1, b'>') else {
            break;
        };
        let body_start = gt + 1;
        let Some(end) = find_ci(html, body_start, b"</script>", 0) else {
            break;
        };
        let body = &html[body_start..end];
        if body.contains(&0) {
            return false;
        }
        out.push(TAG_JSONLD);
        out.extend(body);
        out.push(0);
        pos = end + 9;
        at = at.max(pos);
    }
    true
}

/// End (`>` index) of a tag whose name ends at `from`, honouring quoted attribute values.
fn tag_end(html: &[u8], from: usize) -> Option<usize> {
    let mut quote = 0u8;
    let mut prev = 0u8;
    for (j, &b) in html.iter().enumerate().skip(from) {
        if quote != 0 {
            if b == quote {
                quote = 0;
                prev = b;
            }
        } else if b == b'>' {
            return Some(j);
        } else if (b == b'"' || b == b'\'') && prev == b'=' {
            quote = b;
        } else if !b.is_ascii_whitespace() {
            prev = b;
        }
    }
    None
}

/// Value of the first `data-testid` attribute in `attrs` (the text between the tag name and `>`).
fn data_testid(attrs: &[u8]) -> Option<&[u8]> {
    let n = attrs.len();
    let mut i = 0;
    while i < n {
        while i < n && (attrs[i].is_ascii_whitespace() || attrs[i] == b'/') {
            i += 1;
        }
        let name_start = i;
        while i < n && !attrs[i].is_ascii_whitespace() && !matches!(attrs[i], b'=' | b'/') {
            i += 1;
        }
        let name = &attrs[name_start..i];
        while i < n && attrs[i].is_ascii_whitespace() {
            i += 1;
        }
        let mut value: &[u8] = &[];
        if i < n && attrs[i] == b'=' {
            i += 1;
            while i < n && attrs[i].is_ascii_whitespace() {
                i += 1;
            }
            if i < n && matches!(attrs[i], b'"' | b'\'') {
                let q = attrs[i];
                let start = i + 1;
                let end = find_byte(attrs, start, q).unwrap_or(n);
                value = &attrs[start..end];
                i = (end + 1).min(n);
            } else {
                let start = i;
                while i < n && !attrs[i].is_ascii_whitespace() {
                    i += 1;
                }
                value = &attrs[start..i];
            }
        }
        if name.eq_ignore_ascii_case(b"data-testid") {
            return Some(value);
        }
    }
    None
}

enum Languages {
    Found(usize),
    /// Markup too irregular to mirror the DOM selector exactly.
    UseDom,
}

/// Collects the texts of `li[data-testid='title-details-languages'] a` as `(start, end)` ranges.
/// Anything the HTML tree builder could treat differently from this linear scan yields `UseDom`.
fn scan_languages(html: &[u8], ranges: &mut [(usize, usize); MAX_LANGUAGES]) -> Languages {
    const MARKER: &[u8] = LANGUAGE_MARKER;
    let n = html.len();
    let mut count = 0usize;
    let mut i = 0usize;
    let mut in_target = false;
    let mut li_depth = 0usize;
    let mut list_depth = 0usize;
    let mut a_text_start: Option<usize> = None;

    while let Some(lt) = find_byte(html, i, b'<') {
        let after = lt + 1;
        if html[after..].starts_with(b"!--") {
            match find_sub(html, after + 3, b"-->") {
                Some(e) => {
                    if a_text_start.is_some() {
                        return Languages::UseDom;
                    }
                    i = e + 3;
                    continue;
                }
                None => return Languages::UseDom,
            }
        }
        let closing = html.get(after) == Some(&b'/');
        let name_start = if closing { after + 1 } else { after };
        let mut name_end = name_start;
        while name_end < n && html[name_end].is_ascii_alphanumeric() {
            name_end += 1;
        }
        if name_end == name_start {
            i = after;
            continue;
        }
        let name = &html[name_start..name_end];
        let Some(gt) = tag_end(html, name_end) else {
            return Languages::UseDom;
        };

        if !closing && is_raw_text(name) {
            // Skip script/style/title/textarea bodies; their text is not markup.
            let mut lower = [0u8; 10];
            let nl = name.len();
            for (d, s) in lower.iter_mut().zip(name) {
                *d = s.to_ascii_lowercase();
            }
            let mut close = [0u8; 12];
            close[..2].copy_from_slice(b"</");
            close[2..2 + nl].copy_from_slice(&lower[..nl]);
            match find_ci(html, gt + 1, &close[..2 + nl], 0) {
                Some(p) => {
                    if a_text_start.is_some() {
                        return Languages::UseDom;
                    }
                    i = p;
                    // Step past the closing tag marker so the loop re-reads `</script>` normally.
                    continue;
                }
                None => {
                    return if in_target {
                        Languages::UseDom
                    } else {
                        Languages::Found(count)
                    };
                }
            }
        }

        if let Some(text_start) = a_text_start {
            // Inside an <a> only its closing tag is understood; any other markup means nested content.
            if closing && name.eq_ignore_ascii_case(b"a") {
                let text = php_trim(&html[text_start..lt]);
                // Plain printable ASCII (interior tabs/newlines kept verbatim); entities and
                // multi-byte text are left to the DOM, which decodes them.
                if !text
                    .iter()
                    .all(|&b| ((0x20..0x7F).contains(&b) || b == b'\t' || b == b'\n') && b != b'&')
                {
                    return Languages::UseDom;
                }
                if !text.is_empty() {
                    if count == MAX_LANGUAGES {
                        return Languages::UseDom;
                    }
                    let start = text.as_ptr() as usize - html.as_ptr() as usize;
                    ranges[count] = (start, start + text.len());
                    count += 1;
                }
                a_text_start = None;
                i = gt + 1;
                continue;
            }
            return Languages::UseDom;
        }

        if name.eq_ignore_ascii_case(b"li") {
            if closing {
                if in_target {
                    li_depth -= 1;
                    if li_depth == 0 {
                        in_target = false;
                        if list_depth != 0 {
                            return Languages::UseDom;
                        }
                    }
                }
            } else if in_target {
                if list_depth == 0 {
                    // A bare <li> inside <li> auto-closes the parent in the HTML parser.
                    return Languages::UseDom;
                }
                li_depth += 1;
            } else {
                let attrs = &html[name_end..gt];
                match data_testid(attrs) {
                    Some(v) if v == MARKER => {
                        in_target = true;
                        li_depth = 1;
                        list_depth = 0;
                    }
                    _ if find_ci(attrs, 0, MARKER, 0).is_some() => return Languages::UseDom,
                    _ => {}
                }
            }
        } else if in_target {
            if name.eq_ignore_ascii_case(b"ul") || name.eq_ignore_ascii_case(b"ol") {
                if closing {
                    if list_depth == 0 {
                        return Languages::UseDom;
                    }
                    list_depth -= 1;
                } else {
                    list_depth += 1;
                }
            } else if !closing && name.eq_ignore_ascii_case(b"a") {
                if html[..gt].ends_with(b"/") {
                    return Languages::UseDom;
                }
                a_text_start = Some(gt + 1);
            }
        }
        i = gt + 1;
    }

    if in_target || a_text_start.is_some() {
        Languages::UseDom
    } else {
        Languages::Found(count)
    }
}

#[inline]
fn is_raw_text(name: &[u8]) -> bool {
    [b"script".as_slice(), b"style", b"title", b"textarea"]
        .iter()
        .any(|raw| name.eq_ignore_ascii_case(raw))
}

fn find_sub(hay: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    let first = needle[0];
    let mut cursor = from;
    while let Some(p) = find_byte(hay, cursor, first) {
        if hay[p..].starts_with(needle) {
            return Some(p);
        }
        cursor = p + 1;
    }
    None
}

/// Extracts JSON-LD script bodies and title-detail language link texts as NUL-terminated sections.
///
/// Each section is a tag byte followed by its payload and a NUL: `J` + JSON-LD body, `L` + language text,
/// or a lone `D` when the language markup must be resolved with the DOM. Returns the byte length written,
/// `0` when nothing was found, `-N` when `N` bytes are required, and `-1` when the caller must fall back
/// to the PHP implementation.
///
/// # Safety
/// `html` must point to `html_len` readable bytes and `out_buf` to `out_cap` writable bytes (either may be
/// null with a zero length).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn nntmux_imdb_extract_jsonld_and_languages(
    html: *const u8,
    html_len: usize,
    out_buf: *mut u8,
    out_cap: usize,
) -> isize {
    let html = unsafe { bytes(html, html_len) };
    let mut out = unsafe { OutCursor::new(out_buf, out_cap) };
    if !emit_jsonld(html, &mut out) {
        return UNSUPPORTED;
    }

    let mut ranges = [(0usize, 0usize); MAX_LANGUAGES];
    // IMDb never entity-encodes the hyphenated test id, so a page without the literal marker has no
    // language list and the tokenizing scan is skipped.
    let scan = if find_ci(html, 0, LANGUAGE_MARKER, 17).is_some() {
        scan_languages(html, &mut ranges)
    } else {
        Languages::Found(0)
    };
    match scan {
        Languages::Found(count) => {
            for &(start, end) in &ranges[..count] {
                out.push(TAG_LANGUAGE);
                out.extend(&html[start..end]);
                out.push(0);
            }
        }
        Languages::UseDom => {
            out.push(TAG_LANGUAGE_DOM);
            out.push(0);
        }
    }
    out.finish()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn extract(html: &str) -> (isize, Vec<u8>) {
        let mut buf = vec![0u8; 4096];
        let rc = unsafe {
            nntmux_imdb_extract_jsonld_and_languages(
                html.as_ptr(),
                html.len(),
                buf.as_mut_ptr(),
                buf.len(),
            )
        };
        let used = rc.max(0) as usize;
        buf.truncate(used);
        (rc, buf)
    }

    fn sections(html: &str) -> Vec<String> {
        let (rc, buf) = extract(html);
        assert!(rc >= 0, "rc={rc}");
        buf.split(|&b| b == 0)
            .filter(|s| !s.is_empty())
            .map(|s| String::from_utf8_lossy(s).into_owned())
            .collect()
    }

    fn waf(html: &str) -> i32 {
        unsafe { nntmux_imdb_is_waf_response(html.as_ptr(), html.len()) }
    }

    #[test]
    fn waf_markers_match_case_insensitively() {
        assert_eq!(waf("<html>window.awsWafCookieDomainList=[]</html>"), 1);
        assert_eq!(waf("x window.GOKUPROPS y"), 1);
        assert_eq!(waf("<script src=CHALLENGE.JS>"), 1);
        assert_eq!(waf("please solve the CaptCha"), 1);
        assert_eq!(waf("AWSWAF"), 1);
        assert_eq!(waf("<html><body>An ordinary page</body></html>"), 0);
        assert_eq!(waf(""), 0);
    }

    #[test]
    fn waf_defers_on_kelvin_sign_only_when_unmatched() {
        assert_eq!(waf("window.go\u{212A}uprops"), -1);
        assert_eq!(waf("go\u{212A} captcha"), 1);
        assert_eq!(waf("caf\u{e9} \u{2019}quoted\u{2019}"), 0);
    }

    #[test]
    fn jsonld_blocks_follow_the_php_regex() {
        let html = concat!(
            "<script>var a=1;</script>",
            "<SCRIPT data-x=1 TYPE='application/LD+JSON'>{\"a\":1}</Script>",
            "<script type=\"application/ld+json\">\n{\"b\":2}\n</script>",
            "<script type=\"application/ld+json'>{\"quote\":\"mismatch\"}</script>",
            "<script type=\"text/javascript\">x</script>",
        );
        assert_eq!(
            sections(html),
            vec!["J{\"a\":1}", "J\n{\"b\":2}\n", "J{\"quote\":\"mismatch\"}",]
        );
    }

    #[test]
    fn jsonld_without_close_tag_matches_nothing() {
        assert_eq!(
            sections("<script type=\"application/ld+json\">{\"a\":1}"),
            Vec::<String>::new()
        );
        // An unterminated open tag matches no JSON-LD and the page has no language markup.
        assert_eq!(
            sections("<script type=\"application/ld+json\""),
            Vec::<String>::new()
        );
    }

    #[test]
    fn jsonld_with_nul_requests_php_fallback() {
        let (rc, _) = extract("<script type=\"application/ld+json\">{\0}</script>");
        assert_eq!(rc, UNSUPPORTED);
    }

    #[test]
    fn script_prefix_tags_match_like_the_regex() {
        // `<script[^>]*` also accepts longer tag names.
        assert_eq!(
            sections("<scripts type=\"application/ld+json\">{}</script>"),
            vec!["J{}"]
        );
    }

    #[test]
    fn languages_from_nested_imdb_markup() {
        let html = r#"<ul><li data-testid="other"><a>No</a></li>
            <li role="presentation" data-testid="title-details-languages"><span>Languages</span>
              <div><ul><li><a href="/x">English</a></li><li><a href="/y"> Spanish </a></li>
              <li><a href="/z"></a></li></ul></div></li>
            <li><a>After</a></li></ul>"#;
        assert_eq!(sections(html), vec!["LEnglish", "LSpanish"]);
    }

    #[test]
    fn languages_accept_single_and_unquoted_attributes() {
        assert_eq!(
            sections("<li data-testid='title-details-languages'><a>French</a></li>"),
            vec!["LFrench"]
        );
        assert_eq!(
            sections("<LI DATA-TESTID=title-details-languages><A>German</A></LI>"),
            vec!["LGerman"]
        );
    }

    #[test]
    fn languages_ignore_comments_scripts_and_other_elements() {
        let html = concat!(
            "<!-- <li data-testid=\"title-details-languages\"><a>Hidden</a></li> -->",
            "<script>var s='<li data-testid=\"title-details-languages\"><a>Script</a></li>';</script>",
            "<div data-testid=\"title-details-languages\"><a>Div</a></div>",
        );
        assert_eq!(sections(html), Vec::<String>::new());
    }

    #[test]
    fn irregular_language_markup_defers_to_the_dom() {
        for html in [
            "<li data-testid=\"title-details-languages\"><a>Fran&ccedil;ais</a></li>",
            "<li data-testid=\"title-details-languages\"><a><span>Nested</span></a></li>",
            "<li data-testid=\"title-details-languages\"><a>Espa\u{f1}ol</a></li>",
            "<li data-testid=\"title-details-languages\"><a>Open",
            "<li data-testid=\"title-details-languages\"><a>One</a><li>Bare</li></li>",
            "<li data-testid=\"title-details-languages\"><a>Unclosed</a>",
            "<li data-testid=\"title-details-languages-extra\"><a>Odd</a></li>",
        ] {
            assert_eq!(sections(html), vec!["D"], "{html}");
        }
    }

    #[test]
    fn small_buffers_report_required_size() {
        let html = "<script type=\"application/ld+json\">{\"a\":1}</script>";
        let mut tiny = [0u8; 2];
        let rc = unsafe {
            nntmux_imdb_extract_jsonld_and_languages(
                html.as_ptr(),
                html.len(),
                tiny.as_mut_ptr(),
                tiny.len(),
            )
        };
        assert_eq!(rc, -("J{\"a\":1}\0".len() as isize));
    }

    #[test]
    fn empty_and_null_inputs_are_safe() {
        let rc = unsafe {
            nntmux_imdb_extract_jsonld_and_languages(std::ptr::null(), 0, std::ptr::null_mut(), 0)
        };
        assert_eq!(rc, 0);
        assert_eq!(
            unsafe { nntmux_imdb_is_waf_response(std::ptr::null(), 0) },
            0
        );
    }

    #[test]
    fn byte_search_implementations_agree() {
        let mut hay = vec![b'x'; 300];
        for (at, needle) in [(0, b'a'), (31, b'A'), (32, b'a'), (150, b'A'), (299, b'a')] {
            hay.iter_mut().for_each(|b| *b = b'x');
            hay[at] = needle;
            for from in [0, 1, 31, 32, 33, 150, 299, 300, 301] {
                assert_eq!(
                    find_byte2(&hay, from, b'a', b'A'),
                    find_byte2_portable(&hay, from, b'a', b'A'),
                    "at={at} from={from}"
                );
            }
        }
    }
}
