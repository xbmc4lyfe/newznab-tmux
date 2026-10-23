use flate2::{Compression, write::GzEncoder};
use nntmux_fingerprint_worker::database::{self, Claim};
use sha2::{Digest, Sha256};
use std::{io::Write, sync::Arc};
fn claim() -> Claim {
    claim_bytes(b"<nzb>fixture</nzb>")
}
fn claim_bytes(xml: &[u8]) -> Claim {
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
        held_slots: Arc::new(tokio::sync::Mutex::new(0)),
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

#[test]
fn declared_xml_encodings_preserve_manifest_and_original_digest() {
    use nntmux_fingerprint_worker::manifest;
    let template = r#"<?xml version="1.0" encoding="ENCODING"?><nzb><file subject="café.rar" poster="Müller"><groups><group>a.b</group></groups><segments><segment bytes="3" number="1">x@y</segment></segments></file></nzb>"#;
    let latin = template
        .replace("ENCODING", "ISO-8859-1")
        .chars()
        .map(|c| c as u8)
        .collect::<Vec<_>>();
    let mut sources = vec![latin];
    for little in [true, false] {
        let mut bytes = if little {
            vec![0xff, 0xfe]
        } else {
            vec![0xfe, 0xff]
        };
        for unit in template.replace("ENCODING", "UTF-16").encode_utf16() {
            bytes.extend(if little {
                unit.to_le_bytes()
            } else {
                unit.to_be_bytes()
            });
        }
        sources.push(bytes);
    }
    for source in sources {
        let mut claim = claim_bytes(&source);
        let xml = database::decode_nzb(&claim).unwrap();
        let files = manifest::parse(&xml).unwrap();
        assert_eq!(files[0].subject, "café.rar");
        assert_eq!(files[0].poster, "Müller");
        assert_eq!(files[0].segments[0].messageid, "x@y");
        claim.source_digest = "0".repeat(64);
        assert!(
            database::decode_nzb(&claim)
                .unwrap_err()
                .to_string()
                .contains("NzbSourceDigestMismatch")
        );
    }
}
#[test]
fn invalid_xml_encoding_is_rejected_without_replacement() {
    for source in [
        b"<?xml version='1.0' encoding='unknown'?><nzb/>".as_slice(),
        b"<?xml version='1.0' encoding='UTF-8'?><nzb>\xff</nzb>",
        b"\xff\xfe<\0?",
        b"\xff\xfe\x00\xd8",
    ] {
        assert!(database::decode_nzb(&claim_bytes(source)).is_err());
    }
}
