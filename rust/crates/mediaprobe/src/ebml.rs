//! A forgiving EBML reader for the start of a Matroska file.
//!
//! The probe only ever holds a prefix of the file, so every read reports how far it got instead of
//! failing: an element whose body runs past the buffer is returned as [`Element::truncated`].

/// One element header and the slice of its body that the buffer holds.
#[derive(Clone, Copy, Debug)]
pub struct Element<'a> {
    pub id: u32,
    /// Offset of the element header within the buffer the iterator walks.
    pub offset: usize,
    /// Offset of the body within that buffer.
    pub body_offset: usize,
    /// Declared body size, or `None` for the unknown size (all ones).
    pub size: Option<u64>,
    /// The part of the body the buffer holds.
    pub body: &'a [u8],
    /// True when the declared body runs past the end of the buffer.
    pub truncated: bool,
}

/// Reads an element ID (the marker bits are kept, as Matroska IDs are written).
pub fn read_id(data: &[u8]) -> Option<(u32, usize)> {
    let first = *data.first()?;
    let len = first.leading_zeros() as usize + 1;
    if len > 4 || data.len() < len {
        return None;
    }
    let id = data[..len]
        .iter()
        .fold(0u32, |acc, &byte| (acc << 8) | u32::from(byte));
    Some((id, len))
}

/// Reads a data size. `Ok(None)` is the reserved "unknown size".
pub fn read_size(data: &[u8]) -> Option<(Option<u64>, usize)> {
    let first = *data.first()?;
    let len = first.leading_zeros() as usize + 1;
    if len > 8 || data.len() < len {
        return None;
    }
    let mask = if len == 8 { 0 } else { 0xFFu8 >> len };
    let mut value = u64::from(first & mask);
    let mut all_ones = (first & mask) == mask;
    for &byte in &data[1..len] {
        value = (value << 8) | u64::from(byte);
        all_ones &= byte == 0xFF;
    }
    Some(((!all_ones).then_some(value), len))
}

/// Reads a signed lacing delta (an EBML vint biased to be signed).
pub fn read_signed(data: &[u8]) -> Option<(i64, usize)> {
    let (value, len) = read_size(data)?;
    let value = value? as i64;
    let bias = (1i64 << (7 * len - 1)) - 1;
    Some((value - bias, len))
}

/// Walks sibling elements in `data`, whose first byte sits at `base` in the outer buffer.
pub struct Children<'a> {
    data: &'a [u8],
    pos: usize,
    base: usize,
}

pub fn children(data: &[u8], base: usize) -> Children<'_> {
    Children { data, pos: 0, base }
}

impl<'a> Iterator for Children<'a> {
    type Item = Element<'a>;

    fn next(&mut self) -> Option<Element<'a>> {
        let rest = self.data.get(self.pos..)?;
        let (id, id_len) = read_id(rest)?;
        let (size, size_len) = read_size(&rest[id_len..])?;
        let header = id_len + size_len;
        let available = rest.len() - header;
        let (body_len, truncated) = match size {
            Some(size) if size as usize <= available && size <= available as u64 => {
                (size as usize, false)
            }
            Some(_) => (available, true),
            // An unknown size runs to the end of what we hold; callers that care (Segment,
            // Cluster) descend into it rather than skipping it.
            None => (available, false),
        };
        let element = Element {
            id,
            offset: self.base + self.pos,
            body_offset: self.base + self.pos + header,
            size,
            body: &rest[header..header + body_len],
            truncated,
        };
        self.pos += if size.is_some() && !truncated {
            header + body_len
        } else {
            // Truncated or unknown: nothing after it can be read as a sibling.
            rest.len()
        };
        Some(element)
    }
}

/// An unsigned integer body (up to 8 bytes).
pub fn uint(body: &[u8]) -> Option<u64> {
    (body.len() <= 8).then(|| body.iter().fold(0u64, |acc, &b| (acc << 8) | u64::from(b)))
}

/// A float body (4 or 8 bytes).
pub fn float(body: &[u8]) -> Option<f64> {
    match body.len() {
        4 => Some(f64::from(f32::from_be_bytes(body.try_into().ok()?))),
        8 => Some(f64::from_be_bytes(body.try_into().ok()?)),
        _ => None,
    }
}

/// A string body, cut at the first NUL as the spec allows.
pub fn string(body: &[u8]) -> String {
    let end = body.iter().position(|&b| b == 0).unwrap_or(body.len());
    String::from_utf8_lossy(&body[..end]).trim().to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_ids_and_sizes() {
        assert_eq!(read_id(&[0x1A, 0x45, 0xDF, 0xA3]), Some((0x1A45_DFA3, 4)));
        assert_eq!(read_id(&[0xA3]), Some((0xA3, 1)));
        assert_eq!(read_size(&[0x81]), Some((Some(1), 1)));
        assert_eq!(read_size(&[0x40, 0x02]), Some((Some(2), 2)));
        assert_eq!(read_size(&[0xFF]), Some((None, 1)));
        assert_eq!(
            read_size(&[0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF]),
            Some((None, 8))
        );
        assert_eq!(read_size(&[0x00]), None);
    }

    #[test]
    fn signed_lacing_deltas() {
        // 0xBF is 63 with a one-byte bias of 63, so zero.
        assert_eq!(read_signed(&[0xBF]), Some((0, 1)));
        assert_eq!(read_signed(&[0xC0]), Some((1, 1)));
        assert_eq!(read_signed(&[0xBE]), Some((-1, 1)));
    }

    #[test]
    fn marks_truncated_children() {
        // Two children: a complete 0x86 with 2 bytes, then a 0xA3 that declares 10 but has 3.
        let data = [0x86, 0x82, b'h', b'i', 0xA3, 0x8A, 1, 2, 3];
        let kids: Vec<_> = children(&data, 100).collect();
        assert_eq!(kids.len(), 2);
        assert_eq!(kids[0].body, b"hi");
        assert!(!kids[0].truncated);
        assert_eq!(kids[0].offset, 100);
        assert_eq!(kids[1].body, &[1, 2, 3]);
        assert!(kids[1].truncated);
        assert_eq!(kids[1].body_offset, 106);
    }
}
