use flate2::{Compression, write::GzEncoder};
use nntmux_fingerprint_worker::database::{self, Claim};
use sha2::{Digest, Sha256};
use std::{
    io::Write,
    sync::{Arc, atomic::AtomicU32},
};
fn claim() -> Claim {
    let xml = b"<nzb>fixture</nzb>";
    let mut encoder = GzEncoder::new(vec![], Compression::default());
    encoder.write_all(xml).unwrap();
    let mut sha = Sha256::new();
    sha.update(b"nntmux:nzb-archive-naming:1\0");
    sha.update(xml);
    Claim {
        release_id: 1,
        guid: "fixture".into(),
        body: encoder.finish().unwrap(),
        source_digest: hex::encode(sha.finalize()),
        token: database::token(),
        attempts: 1,
        held_slots: Arc::new(AtomicU32::new(0)),
    }
}
#[test]
fn valid_gzip() {
    assert_eq!(
        database::decode_nzb(&claim()).unwrap(),
        "<nzb>fixture</nzb>"
    );
}
#[test]
fn trailing_bytes_rejected() {
    let mut c = claim();
    c.body.push(0);
    assert!(database::decode_nzb(&c).is_err());
}
#[test]
fn concatenated_members_rejected() {
    let mut c = claim();
    c.body.extend(claim().body);
    assert!(database::decode_nzb(&c).is_err());
}
#[test]
fn truncated_gzip_rejected() {
    let mut c = claim();
    c.body.truncate(c.body.len() - 1);
    assert!(database::decode_nzb(&c).is_err());
}
#[test]
fn corrupt_gzip_rejected() {
    let mut c = claim();
    let n = c.body.len();
    c.body[n - 8] ^= 1;
    assert!(database::decode_nzb(&c).is_err());
}
#[test]
fn wrong_source_digest_rejected() {
    let mut c = claim();
    c.source_digest = "0".repeat(64);
    assert!(database::decode_nzb(&c).is_err());
}
