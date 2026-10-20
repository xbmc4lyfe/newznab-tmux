use anyhow::{Result, bail};
use quick_xml::{Reader, events::Event};
use regex::Regex;
use serde::{Deserialize, Serialize};
use std::collections::HashSet;
use std::sync::LazyLock;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Segment {
    pub number: i64,
    pub bytes: Option<i64>,
    pub messageid: String,
}
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct File {
    pub index: usize,
    pub subject: String,
    pub poster: String,
    pub date: Option<i64>,
    pub groups: Vec<String>,
    pub segments: Vec<Segment>,
    pub kind: String,
    pub format_hint: Option<String>,
    pub advertised_bytes: Option<i64>,
    pub invalid: bool,
}
#[derive(Debug, Clone)]
pub struct Sample {
    pub file_index: usize,
    pub kind: String,
    pub segment_number: i64,
    pub messageid: String,
    pub role: String,
}
static FORMAT: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"(?i-u)\.([a-z0-9]{1,8})\b").unwrap());
static PAR2_KIND: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"(?i-u)\.par2\b").unwrap());
static RAR_KIND: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"(?i-u)\.(?:part\d+\.rar|rar|r\d{2,})\b").unwrap());
static STANDARD_DTD: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r#"(?i)<!DOCTYPE\s+nzb\s+PUBLIC\s+[\"']-//newzBin//DTD NZB 1\.1//EN[\"']\s+[\"']http://www\.newzbin\.com/DTD/nzb/nzb-1\.1\.dtd[\"']\s*>"#).unwrap()
});
fn integer(s: &str) -> Option<i64> {
    if s.is_empty() || !s.bytes().all(|b| b.is_ascii_digit()) {
        None
    } else {
        s.parse().ok()
    }
}
fn attr(e: &quick_xml::events::BytesStart<'_>, key: &[u8]) -> Result<String> {
    for a in e.attributes() {
        let a = a?;
        if a.key.as_ref() == key {
            return Ok(a.unescape_value()?.into_owned());
        }
    }
    Ok(String::new())
}
fn path(stack: &[Vec<u8>], want: &[&[u8]]) -> bool {
    stack.len() == want.len() && stack.iter().zip(want).all(|(a, b)| a.as_slice() == *b)
}
fn local(bytes: &[u8]) -> &[u8] {
    bytes.rsplit(|b| *b == b':').next().unwrap_or(bytes)
}
pub fn parse(xml: &str) -> Result<Vec<File>> {
    if xml.len() > 33_554_432 || xml.to_ascii_uppercase().contains("<!ENTITY") {
        bail!("NzbManifestInvalid");
    }
    let xml = STANDARD_DTD.replace_all(xml, "");
    if xml.to_ascii_uppercase().contains("<!DOCTYPE") {
        bail!("NzbManifestInvalid");
    }
    let mut reader = Reader::from_str(&xml);
    reader.config_mut().trim_text(false);
    let mut stack: Vec<Vec<u8>> = vec![];
    let mut files = vec![];
    let mut current: Option<File> = None;
    let mut segment: Option<Segment> = None;
    let mut text = String::new();
    let mut root_seen = false;
    loop {
        match reader.read_event()? {
            Event::Start(e) => {
                let name = local(e.name().as_ref()).to_vec();
                if stack.is_empty() {
                    if name != b"nzb" || root_seen {
                        bail!("NzbManifestInvalid");
                    }
                    root_seen = true;
                }
                if name == b"file" && stack.as_slice() == [b"nzb".to_vec()] {
                    let subject = attr(&e, b"subject")?.trim().to_string();
                    let hint = FORMAT.captures(&subject).map(|c| c[1].to_ascii_lowercase());
                    let kind = if PAR2_KIND.is_match(&subject) {
                        "par2"
                    } else if RAR_KIND.is_match(&subject) {
                        "rar"
                    } else {
                        "other"
                    }
                    .to_string();
                    current = Some(File {
                        index: files.len(),
                        subject,
                        poster: attr(&e, b"poster")?,
                        date: integer(attr(&e, b"date")?.trim()),
                        kind,
                        format_hint: Some(hint.unwrap_or_else(|| "unknown".into())),
                        advertised_bytes: Some(0),
                        ..File::default()
                    });
                }
                if name == b"segment"
                    && stack.as_slice() == [b"nzb".to_vec(), b"file".to_vec(), b"segments".to_vec()]
                    && let Some(file) = current.as_mut()
                {
                    let n = integer(attr(&e, b"number")?.trim());
                    let bytes = integer(attr(&e, b"bytes")?.trim());
                    if n.is_none_or(|n| n < 1) || bytes.is_none() {
                        file.invalid = true;
                    }
                    segment = Some(Segment {
                        number: n.unwrap_or(0),
                        bytes,
                        messageid: String::new(),
                    });
                    text.clear();
                }
                if name == b"group" && path(&stack, &[b"nzb", b"file", b"groups"]) {
                    text.clear();
                }
                stack.push(name);
            }
            Event::Text(e) => {
                let decoded = e.decode()?;
                if stack.is_empty() && !decoded.trim().is_empty() {
                    bail!("NzbManifestInvalid");
                }
                if stack
                    .last()
                    .is_some_and(|n| n == b"group" || n == b"segment")
                {
                    text.push_str(&quick_xml::escape::unescape(&decoded)?);
                }
            }
            Event::CData(e) => text.push_str(&e.decode()?),
            Event::GeneralRef(e) => {
                let n = e.decode()?;
                if let Some(c) = e.resolve_char_ref()? {
                    text.push(c);
                } else {
                    match n.as_ref() {
                        "amp" => text.push('&'),
                        "lt" => text.push('<'),
                        "gt" => text.push('>'),
                        "quot" => text.push('"'),
                        "apos" => text.push('\''),
                        _ => bail!("NzbManifestInvalid"),
                    }
                }
            }
            Event::Empty(e) => {
                if local(e.name().as_ref()) == b"file" && path(&stack, &[b"nzb"]) {
                    let subject = attr(&e, b"subject")?.trim().to_string();
                    let hint = FORMAT
                        .captures(&subject)
                        .map(|c| c[1].to_ascii_lowercase())
                        .unwrap_or_else(|| "unknown".into());
                    let kind = if PAR2_KIND.is_match(&subject) {
                        "par2"
                    } else if RAR_KIND.is_match(&subject) {
                        "rar"
                    } else {
                        "other"
                    };
                    files.push(File {
                        index: files.len(),
                        subject,
                        poster: attr(&e, b"poster")?,
                        date: integer(attr(&e, b"date")?.trim()),
                        kind: kind.into(),
                        format_hint: Some(hint),
                        advertised_bytes: Some(0),
                        invalid: true,
                        ..File::default()
                    });
                }
                if local(e.name().as_ref()) == b"segment"
                    && path(&stack, &[b"nzb", b"file", b"segments"])
                    && let Some(f) = current.as_mut()
                {
                    f.invalid = true;
                }
            }
            Event::End(e) => {
                let name = local(e.name().as_ref()).to_vec();
                if stack.pop().as_deref() != Some(name.as_slice()) {
                    bail!("NzbManifestInvalid");
                }
                if name == b"segment"
                    && path(&stack, &[b"nzb", b"file", b"segments"])
                    && let Some(mut s) = segment.take()
                {
                    s.messageid = text.trim().trim_matches(['<', '>']).to_string();
                    let f = current.as_mut().unwrap();
                    if s.messageid.is_empty()
                        || s.messageid
                            .bytes()
                            .any(|b| b <= 32 || b == 127 || b == b'<' || b == b'>')
                    {
                        f.invalid = true;
                    } else {
                        f.advertised_bytes = f
                            .advertised_bytes
                            .zip(s.bytes)
                            .and_then(|(a, b)| a.checked_add(b));
                        f.segments.push(s);
                    }
                }
                if name == b"group"
                    && path(&stack, &[b"nzb", b"file", b"groups"])
                    && let Some(f) = current.as_mut()
                {
                    f.groups.push(text.trim().to_string());
                }
                if name == b"file"
                    && path(&stack, &[b"nzb"])
                    && let Some(mut f) = current.take()
                {
                    f.segments.sort_by_key(|s| s.number);
                    let mut ids = HashSet::new();
                    for (i, s) in f.segments.iter().enumerate() {
                        if s.number != i as i64 + 1 || !ids.insert(s.messageid.clone()) {
                            f.invalid = true;
                        }
                    }
                    if f.segments.is_empty() || f.advertised_bytes.is_none() {
                        f.invalid = true;
                    }
                    files.push(f);
                }
                text.clear();
            }
            Event::DocType(_) => bail!("NzbManifestInvalid"),
            Event::Eof => break,
            _ => {}
        }
    }
    if !stack.is_empty() || files.is_empty() {
        bail!("NzbManifestInvalid");
    }
    Ok(files)
}
pub fn samples(files: &[File]) -> (Vec<Sample>, usize) {
    let mut par: Vec<_> = files
        .iter()
        .filter(|f| !f.segments.is_empty() && f.kind == "par2")
        .collect();
    let mut data: Vec<_> = files
        .iter()
        .filter(|f| !f.segments.is_empty() && f.kind != "par2")
        .collect();
    par.sort_by_key(|f| (f.advertised_bytes.unwrap_or(0), f.index));
    data.sort_by_key(|f| (std::cmp::Reverse(f.advertised_bytes.unwrap_or(0)), f.index));
    let mut candidates = vec![];
    if let Some(f) = par.first() {
        candidates.push((*f, 0, "par2"));
    }
    for f in data.iter().take(3) {
        let last = f.segments.len() - 1;
        candidates.push((*f, 0, "first"));
        if last > 1 {
            candidates.push((*f, last / 2, "middle"));
        }
        if last > 0 {
            candidates.push((*f, last, "last"));
        }
    }
    if let Some(f) = par.get(1) {
        candidates.push((*f, 0, "first"));
    }
    for f in data.iter().skip(3).chain(par.iter().skip(2)) {
        candidates.push((*f, 0, "first"));
    }
    let mut out = vec![];
    let mut omitted = 0;
    let mut seen = HashSet::new();
    for (f, p, role) in candidates {
        let s = &f.segments[p];
        if !seen.insert(s.messageid.clone()) {
            continue;
        }
        if out.len() >= 12 {
            omitted += 1;
            continue;
        }
        out.push(Sample {
            file_index: f.index,
            kind: f.kind.clone(),
            segment_number: s.number,
            messageid: s.messageid.clone(),
            role: role.to_string(),
        });
    }
    (out, omitted)
}
pub fn filename(subject: &str) -> &str {
    subject
        .split_once('"')
        .and_then(|(_, tail)| tail.split_once('"').map(|(name, _)| name))
        .filter(|s| !s.is_empty())
        .unwrap_or(subject)
}
pub fn bounded(s: &str) -> String {
    let mut end = s.len().min(120);
    while !s.is_char_boundary(end) {
        end -= 1;
    }
    s[..end].to_string()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn namespace_and_sort() {
        let f=parse(r#"<n:nzb xmlns:n="urn:nzb"><n:file subject='"x.rar"' poster="p" date="1"><n:groups><n:group>a.b</n:group></n:groups><n:segments><n:segment bytes="3" number="2">b@x</n:segment><n:segment bytes="4" number="1">a@x</n:segment></n:segments></n:file></n:nzb>"#).unwrap();
        assert_eq!(f[0].advertised_bytes, Some(7));
        assert!(!f[0].invalid);
        assert_eq!(f[0].segments[0].messageid, "a@x");
        assert_eq!(samples(&f).0.len(), 2);
    }
    #[test]
    fn rejects_entities() {
        assert!(parse("<!DOCTYPE nzb [<!ENTITY x 'x'>]><nzb/>").is_err());
    }
    #[test]
    fn invalid_sequence_and_overflow() {
        let f=parse(r#"<nzb><file><segments><segment number="2" bytes="9223372036854775807">a</segment><segment number="3" bytes="1">b</segment></segments></file></nzb>"#).unwrap();
        assert!(f[0].invalid);
        assert_eq!(f[0].advertised_bytes, None);
    }
}
