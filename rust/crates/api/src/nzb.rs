//! Reading stored NZBs. Sources live gzipped in `release_nzb_contents`; `NzbSourceGuard`'s digest
//! proves a body is the one that was published.

use std::io::Read;

use anyhow::{Result, bail};
use flate2::read::GzDecoder;
use nntmux_runtime::Ctx;
use sha2::{Digest, Sha256};

const MAX_COMPRESSED_BYTES: usize = 33_554_432;
const MAX_DECODED_BYTES: u64 = 268_435_456;

/// `DatabaseNzbStore::decode`: refuses truncated streams, concatenated members and bombs.
fn decode(body: &[u8]) -> Option<Vec<u8>> {
    if !body.starts_with(&[0x1f, 0x8b]) {
        return None;
    }
    let mut decoder = GzDecoder::new(body);
    let mut xml = Vec::new();
    decoder
        .by_ref()
        .take(MAX_DECODED_BYTES + 1)
        .read_to_end(&mut xml)
        .ok()?;
    let oversized = xml.len() as u64 > MAX_DECODED_BYTES;
    (!xml.is_empty() && !oversized && decoder.into_inner().is_empty()).then_some(xml)
}

/// The decoded NZB, or `None` when this release has none readable. A body whose digest doesn't
/// match is an error: `DatabaseNzbStore::read` throws on it rather than serving a changed source.
pub async fn read(ctx: &Ctx, guid: &str) -> Result<Option<Vec<u8>>> {
    let row: Option<(Vec<u8>, String)> = sqlx::query_as(
        "SELECT body, source_digest FROM release_nzb_contents WHERE guid = ? AND decoded_bytes <= ?",
    )
    .bind(guid)
    .bind(MAX_DECODED_BYTES)
    .fetch_optional(&ctx.db)
    .await?;
    let Some((body, digest)) = row else {
        return Ok(None);
    };
    if body.len() > MAX_COMPRESSED_BYTES {
        return Ok(None);
    }
    let Some(xml) = decode(&body) else {
        bail!("database NZB integrity check failed for {guid}");
    };
    let mut hasher = Sha256::new();
    hasher.update(b"nntmux:nzb-archive-naming:1\0");
    hasher.update(&xml);
    if hex::encode(hasher.finalize()) != digest {
        bail!("database NZB integrity check failed for {guid}");
    }
    Ok(Some(xml))
}

/// `str_replace('file poster="', 'file poster="<uid>-', ...)`: marks a download with its user.
pub fn tag_posters(xml: &[u8], user_id: i64) -> Vec<u8> {
    const NEEDLE: &[u8] = b"file poster=\"";
    let replacement = format!("file poster=\"{user_id}-");
    let mut out = Vec::with_capacity(xml.len() + 1024);
    let mut rest = xml;
    while let Some(at) = rest
        .windows(NEEDLE.len())
        .position(|window| window == NEEDLE)
    {
        out.extend_from_slice(&rest[..at]);
        out.extend_from_slice(replacement.as_bytes());
        rest = &rest[at + NEEDLE.len()..];
    }
    out.extend_from_slice(rest);
    out
}
