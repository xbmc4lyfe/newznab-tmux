//! Non-failing analysis of an article body for fingerprinting. Corruption becomes
//! error codes, so one bad article never aborts a collection run.

use md5::{Digest, Md5};
use serde_json::{Value, json};
use std::collections::BTreeMap;

/// Keeps JSON summaries small; names can be arbitrarily long in hostile posts.
fn bounded(s: &str) -> String {
    let mut end = s.len().min(120);
    while !s.is_char_boundary(end) {
        end -= 1;
    }
    s[..end].to_string()
}

#[derive(Debug)]
pub struct Analysis {
    pub summary: Option<Value>,
    pub decoded: Vec<u8>,
    pub errors: Vec<String>,
    pub prefix_md5: Option<String>,
    pub part: Option<i64>,
    pub total: Option<i64>,
    pub multipart: bool,
}
fn fields(line: &[u8]) -> (BTreeMap<String, String>, bool) {
    let s = String::from_utf8_lossy(line);
    let mut out = BTreeMap::new();
    let mut conflict = false;
    let parts: Vec<_> = s.split_whitespace().skip(1).collect();
    let mut i = 0;
    while i < parts.len() {
        if let Some((k, v)) = parts[i].split_once('=') {
            let value = if k == "name" {
                std::iter::once(v)
                    .chain(parts[i + 1..].iter().copied())
                    .collect::<Vec<_>>()
                    .join(" ")
            } else {
                v.to_string()
            };
            if let Some(old) = out.insert(k.to_string(), value.clone())
                && old != value
            {
                conflict = true;
            }
            if k == "name" {
                break;
            }
        }
        i += 1;
    }
    (out, conflict)
}
fn number(f: &BTreeMap<String, String>, k: &str) -> Option<i64> {
    f.get(k)
        .filter(|s| !s.is_empty() && s.bytes().all(|c| c.is_ascii_digit()))
        .and_then(|s| s.parse().ok())
}
fn valid_numbers(f: &BTreeMap<String, String>, keys: &[&str], allow_zero_size: bool) -> bool {
    keys.iter().all(|k| {
        !f.contains_key(*k)
            || number(f, k).is_some_and(|n| n > 0 || (*k == "size" && allow_zero_size && n == 0))
    })
}
fn crc(f: &BTreeMap<String, String>, k: &str) -> Option<u32> {
    f.get(k)
        .filter(|s| !s.is_empty() && s.len() <= 8 && s.bytes().all(|b| b.is_ascii_hexdigit()))
        .and_then(|s| u32::from_str_radix(s, 16).ok())
}
pub fn analyze(body: &[u8]) -> Analysis {
    let mut result = Analysis {
        summary: None,
        decoded: vec![],
        errors: vec![],
        prefix_md5: None,
        part: None,
        total: None,
        multipart: false,
    };
    let lines: Vec<&[u8]> = body
        .split(|b| *b == b'\n')
        .map(|s| s.strip_suffix(b"\r").unwrap_or(s))
        .collect();
    let starts: Vec<_> = lines
        .iter()
        .enumerate()
        .filter(|(_, s)| s.starts_with(b"=ybegin "))
        .map(|(i, _)| i)
        .collect();
    let ends: Vec<_> = lines
        .iter()
        .enumerate()
        .filter(|(_, s)| s.starts_with(b"=yend "))
        .map(|(i, _)| i)
        .collect();
    if starts.len() != 1 || ends.len() != 1 || starts[0] >= ends[0] {
        result.errors.push("YencUnverified".into());
        return result;
    }
    let start = starts[0];
    let end = ends[0];
    let (h, hc) = fields(lines[start]);
    let (t, tc) = fields(lines[end]);
    let part_lines: Vec<_> = lines
        .iter()
        .enumerate()
        .filter(|(_, s)| s.starts_with(b"=ypart "))
        .map(|(i, _)| i)
        .collect();
    let (p, pc) = if part_lines.len() == 1 {
        fields(lines[part_lines[0]])
    } else {
        (BTreeMap::new(), part_lines.len() > 1)
    };
    let range_present = !p.is_empty();
    let payload_start = if range_present && part_lines[0] == start + 1 {
        start + 2
    } else {
        start + 1
    };
    let mut framing = !(hc || tc || pc)
        && part_lines.len() <= 1
        && (!range_present || part_lines[0] == start + 1);
    for line in &lines[payload_start..end] {
        let mut escaped = false;
        for &byte in *line {
            if escaped {
                result.decoded.push(byte.wrapping_sub(64).wrapping_sub(42));
                escaped = false;
            } else if byte == b'=' {
                escaped = true;
            } else {
                result.decoded.push(byte.wrapping_sub(42));
            }
        }
        if escaped {
            framing = false;
        }
    }
    let size = number(&h, "size");
    let part = number(&h, "part");
    let total = number(&h, "total");
    let begin = number(&p, "begin");
    let finish = number(&p, "end");
    let multipart = h.contains_key("part") || t.contains_key("part") || range_present;
    result.part = part;
    result.total = total;
    result.multipart = multipart;
    let decoded_len = result.decoded.len() as i64;
    let range_valid = begin.zip(finish).is_some_and(|(a, b)| {
        a >= 1 && b >= a && (!h.contains_key("size") || size.is_some_and(|s| b <= s))
    });
    let size_checks = [
        number(&t, "size").map(|n| n == decoded_len),
        if multipart {
            begin
                .zip(finish)
                .filter(|_| range_valid)
                .map(|(a, b)| b - a + 1 == decoded_len)
        } else {
            size.map(|n| n == decoded_len)
        },
    ];
    let size_ok = if size_checks.contains(&Some(false)) {
        Some(false)
    } else if size_checks == [Some(true), Some(true)] {
        Some(true)
    } else {
        None
    };
    let observed = crc32fast::hash(&result.decoded);
    let selected = if multipart {
        crc(&t, "pcrc32")
    } else {
        crc(&t, "crc32").or_else(|| crc(&t, "pcrc32"))
    };
    let observed_text = format!("{observed:08x}");
    let fields_to_compare: &[&str] = if multipart {
        &["pcrc32"]
    } else {
        &["crc32", "pcrc32"]
    };
    let contradict = fields_to_compare.iter().any(|k| {
        t.get(*k).is_some_and(|s| {
            format!(
                "{:0>8}",
                s.trim().trim_start_matches('0').to_ascii_lowercase()
            ) != observed_text
        })
    });
    let valid_crc_fields = ["crc32", "pcrc32"]
        .iter()
        .all(|k| !t.contains_key(*k) || crc(&t, k).is_some());
    let structure = if multipart {
        range_present
            && range_valid
            && part.is_some_and(|p| p >= 1)
            && part.zip(total).is_none_or(|(p, t)| p <= t)
            && (!t.contains_key("part") || number(&t, "part") == part)
            && (!t.contains_key("total") || number(&t, "total") == total)
            && (!t.contains_key("total") || t.contains_key("part"))
    } else {
        true
    };
    let positive = framing
        && valid_crc_fields
        && selected == Some(observed)
        && size_ok == Some(true)
        && structure
        && (!multipart || !t.contains_key("part") || t.get("pcrc32").is_some_and(|s| s.len() == 8))
        && valid_numbers(
            &h,
            &["line", "size", "part", "total"],
            !multipart && decoded_len == 0,
        )
        && valid_numbers(&t, &["size", "part", "total"], true);
    if contradict {
        result.errors.push("YencCrcMismatch".into());
    }
    if size_ok == Some(false) {
        result.errors.push("YencSizeMismatch".into());
    }
    if !framing {
        result.errors.push("YencFramingInvalid".into());
    }
    if !positive && result.errors.is_empty() {
        result.errors.push("YencUnverified".into());
    }
    let multipart_complete = structure
        && framing
        && t.contains_key("part")
        && t.get("pcrc32").is_some_and(|s| s.len() == 8)
        && valid_numbers(&h, &["line", "size", "part", "total"], false)
        && valid_numbers(&t, &["size", "part", "total"], true);
    let crc_ok = if selected.is_none() {
        None
    } else if multipart && !multipart_complete {
        if positive && !t.contains_key("part") {
            Some(true)
        } else {
            None
        }
    } else {
        Some(selected == Some(observed) && framing)
    };
    result.summary = Some(
        json!({"name":h.get("name").map(|s|bounded(s)),"size":size,"part":part,"total":total,"begin":begin,"end":finish,"crc_ok":crc_ok,"size_ok":size_ok}),
    );
    if positive
        && (!multipart || begin == Some(1))
        && let Some(size) = size.filter(|s| *s > 0)
    {
        let n = (size as usize).min(16384);
        if result.decoded.len() >= n {
            result.prefix_md5 = Some(hex::encode(Md5::digest(&result.decoded[..n])));
        }
    }
    result
}
#[cfg(test)]
mod tests {
    use super::*;
    pub fn encode(bytes: &[u8], header: &str, trailer: &str) -> Vec<u8> {
        let mut out = format!("{header}\r\n").into_bytes();
        for b in bytes {
            let e = b.wrapping_add(42);
            if [0, 9, 10, 13, 61].contains(&e) {
                out.push(b'=');
                out.push(e.wrapping_add(64));
            } else {
                out.push(e);
            }
        }
        out.extend_from_slice(format!("\r\n{trailer}\r\n").as_bytes());
        out
    }
    #[test]
    fn verified_single() {
        let b = b"abc";
        let body = encode(
            b,
            "=ybegin line=128 size=3 name=a.rar",
            &format!("=yend size=3 crc32={:08x}", crc32fast::hash(b)),
        );
        let a = analyze(&body);
        assert!(a.errors.is_empty());
        assert_eq!(a.decoded, b);
        assert_eq!(a.summary.unwrap()["crc_ok"], true);
    }
    #[test]
    fn mismatch_not_clean() {
        let a = analyze(&encode(
            b"abc",
            "=ybegin line=128 size=3 name=x",
            "=yend size=3 crc32=00000000",
        ));
        assert!(a.errors.contains(&"YencCrcMismatch".to_string()));
    }
    #[test]
    fn dangling_escape_not_clean() {
        let a = analyze(b"=ybegin line=128 size=1 name=x\r\n=\r\n=yend size=0 crc32=00000000\r\n");
        assert!(!a.errors.is_empty());
    }
    #[test]
    fn multipart_without_trailer_part() {
        let b = b"abc";
        let a = analyze(&encode(
            b,
            "=ybegin part=1 total=2 line=128 size=6 name=x\r\n=ypart begin=1 end=3",
            &format!("=yend size=3 pcrc32={:x}", crc32fast::hash(b)),
        ));
        assert!(a.errors.is_empty());
        assert_eq!(a.part, Some(1));
    }
    #[test]
    fn contradictory_declaration_rejected() {
        let b = b"abc";
        let a = analyze(&encode(
            b,
            "=ybegin line=128 size=3 name=x",
            &format!(
                "=yend size=3 crc32={:08x} pcrc32=00000000",
                crc32fast::hash(b)
            ),
        ));
        assert!(!a.errors.is_empty());
    }
}
