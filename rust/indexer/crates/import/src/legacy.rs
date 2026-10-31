//! The old system's NZB format, and checking a body against what it recorded.

use indexer_store::{MAX_DECODED_BYTES, NzbEncoding, NzbWrite, body_sha256, gunzip_capped};
use sha2::{Digest, Sha256};

use crate::convert::Anomaly;

/// `NzbSourceGuard::digest`: SHA-256 of a fixed prefix and the decoded XML, in hex.
pub fn legacy_digest(xml: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(b"nntmux:nzb-archive-naming:1\0");
    hasher.update(xml);
    hex::encode(hasher.finalize())
}

/// An NZB as the source holds it.
#[derive(Debug)]
pub struct SourceNzb {
    pub release_id: i64,
    pub digest: String,
    pub decoded_bytes: i64,
    pub body: Vec<u8>,
}

/// Decodes a body and checks it against the source's digest and size. The bytes are
/// kept whatever the result, because the source is the only copy of them.
pub fn check_nzb(nzb: SourceNzb) -> (NzbWrite, Option<Anomaly>) {
    let release_id = nzb.release_id;
    let anomaly = |kind, detail| {
        Some(Anomaly {
            release_id: Some(release_id),
            kind,
            detail,
        })
    };
    let (decoded_bytes, problem) = match gunzip_capped(&nzb.body, MAX_DECODED_BYTES) {
        Ok(xml) => {
            let problem = if legacy_digest(&xml) != nzb.digest {
                anomaly(
                    "nzb_digest_mismatch",
                    format!("source digest {}", nzb.digest),
                )
            } else if xml.len() as i64 != nzb.decoded_bytes {
                anomaly(
                    "nzb_size_mismatch",
                    format!(
                        "source says {}, decodes to {}",
                        nzb.decoded_bytes,
                        xml.len()
                    ),
                )
            } else {
                None
            };
            (xml.len() as i64, problem)
        }
        Err(error) => (
            nzb.decoded_bytes,
            anomaly("nzb_undecodable", format!("{error:#}")),
        ),
    };
    let write = NzbWrite {
        release_id,
        encoding: NzbEncoding::Gzip,
        decoded_bytes,
        body_sha256: body_sha256(&nzb.body),
        legacy_digest: Some(nzb.digest),
        body: nzb.body,
    };
    (write, problem)
}

#[cfg(test)]
mod tests {
    use std::io::Write;

    use flate2::{Compression, write::GzEncoder};

    use super::*;

    fn gzip(data: &[u8]) -> Vec<u8> {
        let mut encoder = GzEncoder::new(Vec::new(), Compression::fast());
        encoder.write_all(data).unwrap();
        encoder.finish().unwrap()
    }

    #[test]
    fn matches_the_digest_php_computes() {
        // printf 'nntmux:nzb-archive-naming:1\0<nzb/>' | shasum -a 256
        assert_eq!(
            legacy_digest(b"<nzb/>"),
            "1e4f00f796d6374eb8da754db9683ff52f65bf9f0cabedf5679044105728a116"
        );
    }

    #[test]
    fn a_good_body_has_no_anomaly() {
        let body = gzip(b"<nzb/>");
        let (write, anomaly) = check_nzb(SourceNzb {
            release_id: 1,
            digest: legacy_digest(b"<nzb/>"),
            decoded_bytes: 6,
            body: body.clone(),
        });
        assert_eq!(anomaly, None);
        assert_eq!(write.body, body);
        assert_eq!(write.decoded_bytes, 6);
    }

    #[test]
    fn bad_bodies_are_reported_and_kept() {
        let body = gzip(b"<nzb/>");
        let check = |digest: &str, size, body: Vec<u8>| {
            let nzb = SourceNzb {
                release_id: 1,
                digest: digest.into(),
                decoded_bytes: size,
                body,
            };
            let (write, anomaly) = check_nzb(nzb);
            (write.body, anomaly.unwrap().kind)
        };
        let (kept, kind) = check("wrong", 6, body.clone());
        assert_eq!((kept, kind), (body.clone(), "nzb_digest_mismatch"));
        let (kept, kind) = check(&legacy_digest(b"<nzb/>"), 7, body.clone());
        assert_eq!((kept, kind), (body, "nzb_size_mismatch"));
        let (kept, kind) = check("x", 6, b"not gzip".to_vec());
        assert_eq!((kept, kind), (b"not gzip".to_vec(), "nzb_undecodable"));
    }
}
