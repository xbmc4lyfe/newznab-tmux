//! Locates one complete yEnc frame inside an article body.

use std::collections::BTreeMap;

/// Control-line fields, keyed by lowercase name. Values stay raw bytes because
/// filenames in real headers are often not UTF-8.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Fields(BTreeMap<String, Vec<u8>>);

impl Fields {
    pub fn get(&self, key: &str) -> Option<&[u8]> {
        self.0.get(key).map(Vec::as_slice)
    }

    pub fn contains(&self, key: &str) -> bool {
        self.0.contains_key(key)
    }

    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
    }

    pub fn iter(&self) -> impl Iterator<Item = (&str, &[u8])> {
        self.0
            .iter()
            .map(|(key, value)| (key.as_str(), value.as_slice()))
    }

    /// Later occurrences win, and `name=` on a header line consumes the rest of it.
    fn parse(line: &[u8], with_name: bool) -> Self {
        let mut end = line.len();
        while end > 0 && line[end - 1] == b'\r' {
            end -= 1;
        }
        let line = &line[..end];
        let mut fields = BTreeMap::new();
        let mut position = 0;
        while position <= line.len() {
            let Some((key, value, next)) = next_field(line, position, with_name) else {
                break;
            };
            let is_name = key == "name";
            fields.insert(key, value.to_vec());
            if with_name && is_name {
                break;
            }
            position = next;
        }
        Self(fields)
    }
}

/// Finds the next `key=value` token that starts the line or follows a space or tab.
fn next_field(line: &[u8], from: usize, with_name: bool) -> Option<(String, &[u8], usize)> {
    let mut position = from;
    while position <= line.len() {
        if position == 0
            && let Some(found) = field_at(line, 0, with_name)
        {
            return Some(found);
        }
        if line
            .get(position)
            .is_some_and(|byte| matches!(byte, b' ' | b'\t'))
            && let Some(found) = field_at(line, position + 1, with_name)
        {
            return Some(found);
        }
        position += 1;
    }
    None
}

fn field_at(line: &[u8], start: usize, with_name: bool) -> Option<(String, &[u8], usize)> {
    if !line.get(start)?.is_ascii_alphabetic() {
        return None;
    }
    let mut equals = start + 1;
    while line
        .get(equals)
        .is_some_and(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
    {
        equals += 1;
    }
    if line.get(equals) != Some(&b'=') {
        return None;
    }
    let key = String::from_utf8_lossy(&line[start..equals]).to_ascii_lowercase();
    let value_start = equals + 1;
    if with_name && key == "name" {
        return Some((key, &line[value_start..], line.len()));
    }
    let value_end = line[value_start..]
        .iter()
        .position(|byte| matches!(byte, b' ' | b'\t'))
        .map_or(line.len(), |length| value_start + length);
    Some((key, &line[value_start..value_end], value_end))
}

/// One complete frame. The payload span runs from after the header lines up to
/// the `=yend` line, so it includes the final line ending.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ArticleFrame {
    pub header: Fields,
    pub part: Option<Fields>,
    pub trailer: Fields,
    pub payload_offset: usize,
    pub payload_length: usize,
}

impl ArticleFrame {
    /// Finds the first usable frame. A `=ybegin` missing `line`, `size` or `name`
    /// is skipped, as is a frame truncated by a later `=ybegin`. Payload-only
    /// callers can pass `parse_trailer_fields = false`.
    pub fn parse(text: &[u8], parse_trailer_fields: bool) -> Option<Self> {
        let mut offset = 0;
        while let Some(begin) = find_control(text, b"=ybegin", offset) {
            let line_end = find_newline(text, begin)?;
            let header = Fields::parse(&text[begin + 7..line_end], true);
            offset = line_end + 1;
            if !(header.contains("line") && header.contains("size") && header.contains("name")) {
                continue;
            }

            let mut part = None;
            let window = &text[offset..text.len().min(offset + 7)];
            if window.eq_ignore_ascii_case(b"=ypart ") || window.eq_ignore_ascii_case(b"=ypart\t") {
                let part_end = find_newline(text, offset)?;
                part = Some(Fields::parse(&text[offset + 6..part_end], false));
                offset = part_end + 1;
            }

            let end = find_control(text, b"=yend", offset)?;
            if let Some(next_begin) = find_control(text, b"=ybegin", offset)
                && next_begin < end
            {
                offset = next_begin;
                continue;
            }
            let trailer = if parse_trailer_fields {
                let trailer_end = find_newline(text, end).unwrap_or(text.len());
                Fields::parse(&text[end + 5..trailer_end], false)
            } else {
                Fields::default()
            };
            return Some(Self {
                header,
                part,
                trailer,
                payload_offset: offset,
                payload_length: end - offset,
            });
        }
        None
    }

    pub fn payload<'a>(&self, text: &'a [u8]) -> &'a [u8] {
        &text[self.payload_offset..self.payload_offset + self.payload_length]
    }

    /// Whether any control line declares this frame as one part of a multipart file.
    pub fn is_multipart(&self) -> bool {
        self.header.contains("part") || self.trailer.contains("part") || self.part.is_some()
    }
}

fn find_newline(text: &[u8], from: usize) -> Option<usize> {
    text[from..]
        .iter()
        .position(|byte| *byte == b'\n')
        .map(|length| from + length)
}

fn control_ends_at(text: &[u8], after: usize) -> bool {
    text.get(after)
        .is_none_or(|byte| matches!(byte, b' ' | b'\t' | b'\r' | b'\n'))
}

/// A control line starts the text or follows a newline, in any case, and its
/// keyword must end there, so `=yendx` in a payload doesn't count.
fn find_control(text: &[u8], control: &[u8], offset: usize) -> Option<usize> {
    if offset == 0
        && text.len() >= control.len()
        && text[..control.len()].eq_ignore_ascii_case(control)
        && control_ends_at(text, control.len())
    {
        return Some(0);
    }
    let mut from = offset.saturating_sub(1);
    while let Some(newline) = find_newline(text, from) {
        let position = newline + 1;
        let after = position + control.len();
        if after > text.len() {
            return None;
        }
        if text[position..after].eq_ignore_ascii_case(control) && control_ends_at(text, after) {
            return Some(position);
        }
        from = position;
    }
    None
}
