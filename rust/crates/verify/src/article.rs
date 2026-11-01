//! An article body validated against its yEnc frame.

use anyhow::{Result, anyhow, bail};
use nntmux_yenc::ArticleFrame;

/// A decoded article's identity and payload.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct VerifiedArticle {
    pub name: String,
    pub size: i64,
    pub offset: i64,
    pub part: i64,
    pub total_parts: Option<i64>,
    pub bytes: Vec<u8>,
    pub file_checksum: Option<String>,
}

/// The digits-only form PHP's `ctype_digit` accepts, at most 18 of them and at least 1 in value.
fn dimension(value: &[u8]) -> Result<i64> {
    let valid = !value.is_empty() && value.len() <= 18 && value.iter().all(u8::is_ascii_digit);
    let number = if valid {
        std::str::from_utf8(value)?.parse::<i64>()?
    } else {
        0
    };
    if number < 1 {
        bail!("Invalid article dimensions.");
    }
    Ok(number)
}

/// Validates the frame and decodes. A CRC failure and a bad frame are both errors: transport may have
/// corrupted the body, so neither is a deterministic verdict on the upload.
pub fn decode(raw: &[u8]) -> Result<VerifiedArticle> {
    let frame =
        ArticleFrame::parse(raw, true).ok_or_else(|| anyhow!("Unsupported article encoding."))?;
    let name = frame.header.get("name").unwrap_or_default();
    let name = std::str::from_utf8(name).map_err(|_| anyhow!("Unsafe article filename."))?;
    if name.is_empty()
        || name.len() > 200
        || name.starts_with(['.', '-'])
        || name
            .bytes()
            .any(|b| matches!(b, 0x00..=0x1f | 0x7f | b'/' | b'\\'))
    {
        bail!("Unsafe article filename.");
    }
    let size = dimension(frame.header.get("size").unwrap_or_default())?;
    let part = dimension(frame.header.get("part").unwrap_or(b"1"))?;
    let begin = dimension(
        frame
            .part
            .as_ref()
            .and_then(|part| part.get("begin"))
            .unwrap_or(b"1"),
    )?;
    let total_parts = frame.header.get("total").map(dimension).transpose()?;
    if total_parts.is_some_and(|total| part > total) {
        bail!("Article part number exceeds its declared total.");
    }
    let checksum = if frame.part.is_none() {
        "crc32"
    } else {
        "pcrc32"
    };
    if !frame.trailer.contains(checksum) {
        bail!("Article checksum is required.");
    }
    let decoded = nntmux_yenc::decode(raw)?.ok_or_else(|| anyhow!("Article decoding failed."))?;
    let file_checksum = frame
        .trailer
        .get("crc32")
        .map(|crc| format!("{:0>8}", String::from_utf8_lossy(crc)).to_lowercase());
    Ok(VerifiedArticle {
        name: name.to_owned(),
        size,
        offset: begin - 1,
        part,
        total_parts,
        bytes: decoded,
        file_checksum,
    })
}
