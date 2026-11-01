//! Checks the port against `fixtures/manifest_golden.json`, recorded from the PHP reference by
//! `fixtures/generate_manifest_golden.php`.

use nntmux_verify::inventory;
use nntmux_verify::ledger;
use nntmux_verify::manifest::{Manifest, PreparedManifest, php_json};
use nntmux_verify::nzb::{self, ArticleFingerprint};
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/manifest_golden.json")).expect("golden JSON")
}

fn case(entry: &Value) -> Manifest {
    serde_json::from_value(entry["manifest"].clone()).expect("manifest")
}

#[test]
fn manifests_hash_to_the_name_php_gave_them() {
    for (name, entry) in golden()["manifests"].as_object().expect("cases") {
        let manifest = case(entry);
        // Keys PHP left out are absent there too, except the two a collection always writes.
        let json = String::from_utf8(php_json(&manifest).expect("json")).expect("utf8");
        assert_eq!(json, entry["json"].as_str().expect("json"), "{name}");
        let prepared = PreparedManifest::new(&manifest).expect("prepared");
        assert_eq!(
            prepared.name,
            entry["name"].as_str().expect("name"),
            "{name}"
        );
    }
}

#[test]
fn assessments_match() {
    for (name, entry) in golden()["manifests"].as_object().expect("cases") {
        let manifest = case(entry);
        let assessment = inventory::assess(&manifest);
        assert_eq!(assessment.status.as_str(), entry["status"], "{name}");
        assert_eq!(assessment.reason, entry["reason"].as_str(), "{name}");
        assert_eq!(
            inventory::partial_rejection(&manifest),
            entry["partial_rejection"].as_str(),
            "{name}"
        );
        let completeness = inventory::completeness(&manifest);
        assert_eq!(
            serde_json::to_value(completeness).expect("value"),
            entry["completeness"],
            "{name}"
        );
        assert_eq!(
            inventory::bytes(&manifest),
            entry["bytes"].as_i64().expect("bytes"),
            "{name}"
        );
    }
}

#[test]
fn documents_match_byte_for_byte() {
    for (name, entry) in golden()["manifests"].as_object().expect("cases") {
        let manifest = case(entry);
        let document = nzb::document(&manifest, 0).expect("document");
        assert_eq!(
            document,
            entry["document"].as_str().expect("document"),
            "{name}"
        );
        assert_eq!(
            nzb::digest(document.as_bytes()),
            entry["digest"].as_str().expect("digest"),
            "{name}"
        );
    }
}

#[test]
fn fingerprints_match() {
    for entry in golden()["fingerprints"].as_array().expect("cases") {
        let mut fingerprint = ArticleFingerprint::new();
        for id in entry["ids"].as_array().expect("ids") {
            fingerprint.add(id.as_str().expect("id"));
        }
        assert_eq!(
            fingerprint.value().as_deref(),
            entry["value"].as_str(),
            "{entry}"
        );
    }
}

#[test]
fn publication_keys_and_stripes_match() {
    for entry in golden()["keys"].as_array().expect("cases") {
        let keys = ledger::publication_keys(
            entry["size"].as_i64().expect("size"),
            entry["fingerprint"].as_str(),
            entry["name"].as_str().expect("name"),
            entry["search"].as_str().expect("search"),
            entry["predb"].as_i64().expect("predb"),
        );
        assert_eq!(
            serde_json::to_value(&keys).expect("keys"),
            entry["keys"],
            "{entry}"
        );
        let stripes: Vec<String> = keys
            .iter()
            .map(|key| ledger::publication_stripe(key))
            .collect();
        assert_eq!(
            serde_json::to_value(&stripes).expect("stripes"),
            entry["stripes"],
            "{entry}"
        );
    }
}

#[test]
fn stored_bodies_refuse_trailing_members_and_bombs() {
    let body = nzb::compress(b"<nzb/>").expect("compress");
    assert_eq!(nzb::decode(&body, 100).as_deref(), Some(&b"<nzb/>"[..]));
    assert_eq!(nzb::decode(&body, 3), None);
    let mut doubled = body.clone();
    doubled.extend_from_slice(&body);
    assert_eq!(nzb::decode(&doubled, 100), None);
    assert_eq!(nzb::decode(&body[..body.len() - 1], 100), None);
    assert_eq!(nzb::decode(b"not gzip", 100), None);
}
