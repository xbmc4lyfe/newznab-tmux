//! WebAuthn checks for passkeys, on the credential JSON webauthn-lib stored, so passkeys
//! registered under PHP keep working. Attestation is `none` only, as PHP accepted.

use anyhow::{Context, Result, anyhow, bail, ensure};
use base64::Engine;
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use ring::signature::{
    ECDSA_P256_SHA256_ASN1, ED25519, RSA_PKCS1_2048_8192_SHA256, RsaPublicKeyComponents,
    UnparsedPublicKey,
};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

const USER_PRESENT: u8 = 0x01;
const USER_VERIFIED: u8 = 0x04;
const BACKUP_ELIGIBLE: u8 = 0x08;
const BACKUP_STATE: u8 = 0x10;
const ATTESTED: u8 = 0x40;

const ES256: i64 = -7;
const EDDSA: i64 = -8;
const RS256: i64 = -257;

pub fn encode(bytes: &[u8]) -> String {
    URL_SAFE_NO_PAD.encode(bytes)
}

/// Either base64 alphabet, padded or not, as webauthn-lib's decoder took.
pub fn decode(text: &str) -> Result<Vec<u8>> {
    let clean = text
        .trim_end_matches('=')
        .replace('+', "-")
        .replace('/', "_");
    URL_SAFE_NO_PAD.decode(clean).context("invalid base64")
}

#[derive(Debug, PartialEq)]
enum Cbor {
    Int(i64),
    Bytes(Vec<u8>),
    Text(String),
    Array(Vec<Cbor>),
    Map(Vec<(Cbor, Cbor)>),
    Simple,
}

impl Cbor {
    fn field(&self, key: &Cbor) -> Option<&Cbor> {
        let Cbor::Map(entries) = self else {
            return None;
        };
        entries
            .iter()
            .find(|(name, _)| name == key)
            .map(|(_, value)| value)
    }

    fn bytes(&self, key: i64) -> Result<&[u8]> {
        match self.field(&Cbor::Int(key)) {
            Some(Cbor::Bytes(bytes)) => Ok(bytes),
            _ => bail!("key parameter {key} is missing"),
        }
    }

    fn int(&self, key: i64) -> Result<i64> {
        match self.field(&Cbor::Int(key)) {
            Some(Cbor::Int(value)) => Ok(*value),
            _ => bail!("key parameter {key} is missing"),
        }
    }
}

/// One definite-length item and how many bytes it took. Enough for attestation objects.
fn cbor(input: &[u8], depth: usize) -> Result<(Cbor, usize)> {
    ensure!(depth <= 8, "CBOR nests too deeply");
    let first = *input.first().context("CBOR ended early")?;
    let (major, minor) = (first >> 5, first & 0x1f);
    let (argument, mut used) = match minor {
        0..=23 => (u64::from(minor), 1),
        24..=27 => {
            let width = 1usize << (minor - 24);
            let raw = input.get(1..1 + width).context("CBOR ended early")?;
            (
                raw.iter()
                    .fold(0u64, |sum, byte| (sum << 8) | u64::from(*byte)),
                1 + width,
            )
        }
        _ => bail!("unsupported CBOR length"),
    };
    let count = usize::try_from(argument).context("CBOR length too large")?;
    let item = match major {
        0 => Cbor::Int(i64::try_from(argument).context("CBOR integer too large")?),
        1 => Cbor::Int(-1 - i64::try_from(argument).context("CBOR integer too large")?),
        2 | 3 => {
            let end = used.checked_add(count).context("CBOR length too large")?;
            let raw = input.get(used..end).context("CBOR ended early")?;
            used = end;
            if major == 2 {
                Cbor::Bytes(raw.to_vec())
            } else {
                Cbor::Text(std::str::from_utf8(raw)?.to_owned())
            }
        }
        4 => {
            let mut items = Vec::new();
            for _ in 0..count {
                let (value, size) =
                    cbor(input.get(used..).context("CBOR ended early")?, depth + 1)?;
                items.push(value);
                used += size;
            }
            Cbor::Array(items)
        }
        5 => {
            let mut entries = Vec::new();
            for _ in 0..count {
                let (key, size) = cbor(input.get(used..).context("CBOR ended early")?, depth + 1)?;
                used += size;
                let (value, size) =
                    cbor(input.get(used..).context("CBOR ended early")?, depth + 1)?;
                used += size;
                entries.push((key, value));
            }
            Cbor::Map(entries)
        }
        7 if minor < 24 => Cbor::Simple,
        _ => bail!("unsupported CBOR type"),
    };
    Ok((item, used))
}

/// Refuses a key this site can't verify with, whatever the authenticator offered.
fn check_key(key: &Cbor) -> Result<i64> {
    let algorithm = key.int(3)?;
    match algorithm {
        ES256 => {
            ensure!(key.int(1)? == 2 && key.int(-1)? == 1, "not a P-256 key");
            ensure!(
                key.bytes(-2)?.len() == 32 && key.bytes(-3)?.len() == 32,
                "bad P-256 point"
            );
        }
        EDDSA => {
            ensure!(key.int(1)? == 1 && key.int(-1)? == 6, "not an Ed25519 key");
            ensure!(key.bytes(-2)?.len() == 32, "bad Ed25519 key");
        }
        RS256 => {
            ensure!(key.int(1)? == 3, "not an RSA key");
            ensure!(
                key.bytes(-1)?.len() >= 256 && !key.bytes(-2)?.is_empty(),
                "bad RSA key"
            );
        }
        other => bail!("unsupported algorithm {other}"),
    }
    Ok(algorithm)
}

fn verify_signature(cose: &[u8], message: &[u8], signature: &[u8]) -> Result<()> {
    let (key, _) = cbor(cose, 0)?;
    let refused = |_| anyhow!("the signature does not verify");
    match check_key(&key)? {
        ES256 => {
            let mut point = vec![4u8];
            point.extend_from_slice(key.bytes(-2)?);
            point.extend_from_slice(key.bytes(-3)?);
            UnparsedPublicKey::new(&ECDSA_P256_SHA256_ASN1, point)
                .verify(message, signature)
                .map_err(refused)
        }
        EDDSA => UnparsedPublicKey::new(&ED25519, key.bytes(-2)?)
            .verify(message, signature)
            .map_err(refused),
        _ => RsaPublicKeyComponents {
            n: key.bytes(-1)?,
            e: key.bytes(-2)?,
        }
        .verify(&RSA_PKCS1_2048_8192_SHA256, message, signature)
        .map_err(refused),
    }
}

/// The request's origin must be this relying party, over HTTPS unless it is localhost.
fn origin_allowed(origin: &str, rp_id: &str) -> bool {
    let Some((scheme, authority)) = origin.split_once("://") else {
        return false;
    };
    let host = authority.split_once(':').map_or(authority, |(host, port)| {
        if !port.is_empty() && port.bytes().all(|byte| byte.is_ascii_digit()) {
            host
        } else {
            ""
        }
    });
    host == rp_id && (scheme == "https" || (scheme == "http" && rp_id == "localhost"))
}

fn check_client_data(raw: &[u8], kind: &str, challenge: &str, rp_id: &str) -> Result<()> {
    let data: Value = serde_json::from_slice(raw).context("clientDataJSON is not JSON")?;
    ensure!(data["type"] == kind, "wrong ceremony type");
    let sent = data["challenge"].as_str().context("no challenge")?;
    ensure!(
        !challenge.is_empty() && sent == challenge,
        "wrong challenge"
    );
    ensure!(data["crossOrigin"] != json!(true), "cross-origin ceremony");
    let origin = data["origin"].as_str().context("no origin")?;
    ensure!(
        origin_allowed(origin, rp_id),
        "origin {origin} is not allowed"
    );
    Ok(())
}

struct AuthData<'a> {
    flags: u8,
    counter: u32,
    rest: &'a [u8],
}

fn auth_data<'a>(raw: &'a [u8], rp_id: &str) -> Result<AuthData<'a>> {
    ensure!(raw.len() >= 37, "authenticator data is too short");
    ensure!(
        raw[..32] == Sha256::digest(rp_id.as_bytes())[..],
        "wrong relying party"
    );
    let flags = raw[32];
    ensure!(flags & USER_PRESENT != 0, "the user was not present");
    let counter = u32::from_be_bytes([raw[33], raw[34], raw[35], raw[36]]);
    Ok(AuthData {
        flags,
        counter,
        rest: &raw[37..],
    })
}

fn part(response: &Value, name: &str) -> Result<Vec<u8>> {
    decode(
        response[name]
            .as_str()
            .with_context(|| format!("{name} is missing"))?,
    )
}

/// The credential id a browser's response names, in the form stored JSON holds it.
pub fn credential_id(credential: &Value) -> Result<String> {
    let id = credential["rawId"]
        .as_str()
        .or(credential["id"].as_str())
        .context("no credential id")?;
    Ok(encode(&decode(id)?))
}

/// Checks a sign-in and returns the stored JSON as it should be saved afterward.
pub fn verify_assertion(
    credential: &Value,
    stored: &Value,
    challenge: &str,
    rp_id: &str,
) -> Result<Value> {
    ensure!(
        credential["type"] == "public-key",
        "not a public key credential"
    );
    let response = &credential["response"];
    let client_data = part(response, "clientDataJSON")?;
    check_client_data(&client_data, "webauthn.get", challenge, rp_id)?;
    let raw = part(response, "authenticatorData")?;
    let data = auth_data(&raw, rp_id)?;
    if let Some(handle) = response["userHandle"]
        .as_str()
        .filter(|handle| !handle.is_empty())
    {
        let expected = stored["userHandle"]
            .as_str()
            .context("stored credential has no user")?;
        ensure!(decode(handle)? == decode(expected)?, "wrong user handle");
    }
    let key = decode(
        stored["credentialPublicKey"]
            .as_str()
            .context("stored credential has no key")?,
    )?;
    let mut message = raw.clone();
    message.extend_from_slice(&Sha256::digest(&client_data));
    verify_signature(&key, &message, &part(response, "signature")?)?;
    let previous = stored["counter"].as_u64().unwrap_or(0);
    let counter = u64::from(data.counter);
    ensure!(
        (previous == 0 && counter == 0) || counter > previous,
        "the signature counter went backward"
    );
    let mut updated = stored.clone();
    updated["counter"] = json!(counter);
    updated["backupStatus"] = json!(data.flags & BACKUP_STATE != 0);
    if data.flags & USER_VERIFIED != 0 {
        updated["uvInitialized"] = json!(true);
    }
    Ok(updated)
}

/// Checks a registration and returns the credential's id and the JSON to store.
pub fn verify_registration(
    credential: &Value,
    challenge: &str,
    rp_id: &str,
    user_id: i64,
) -> Result<(String, Value)> {
    ensure!(
        credential["type"] == "public-key",
        "not a public key credential"
    );
    let response = &credential["response"];
    check_client_data(
        &part(response, "clientDataJSON")?,
        "webauthn.create",
        challenge,
        rp_id,
    )?;
    let (attestation, _) = cbor(&part(response, "attestationObject")?, 0)?;
    let text = |name: &str| Cbor::Text(name.to_owned());
    ensure!(
        attestation.field(&text("fmt")) == Some(&text("none")),
        "only attestation none is accepted"
    );
    ensure!(
        attestation.field(&text("attStmt")) == Some(&Cbor::Map(Vec::new())),
        "attestation none has a statement"
    );
    let Some(Cbor::Bytes(raw)) = attestation.field(&text("authData")) else {
        bail!("no authenticator data");
    };
    let data = auth_data(raw, rp_id)?;
    ensure!(data.flags & ATTESTED != 0, "no attested credential");
    ensure!(data.rest.len() >= 18, "attested credential is too short");
    let (aaguid, rest) = data.rest.split_at(16);
    let (length, rest) = rest.split_at(2);
    let length = usize::from(u16::from_be_bytes([length[0], length[1]]));
    ensure!(
        (1..=1023).contains(&length) && rest.len() > length,
        "bad credential id length"
    );
    let (id, rest) = rest.split_at(length);
    let (key, size) = cbor(rest, 0)?;
    check_key(&key)?;
    let id = encode(id);
    let aaguid = hex::encode(aaguid);
    let stored = json!({
        "publicKeyCredentialId": id,
        "type": "public-key",
        "transports": response["transports"].as_array().cloned().unwrap_or_default(),
        "attestationType": "none",
        "trustPath": {"type": "Webauthn\\TrustPath\\EmptyTrustPath"},
        "aaguid": format!("{}-{}-{}-{}-{}", &aaguid[..8], &aaguid[8..12], &aaguid[12..16], &aaguid[16..20], &aaguid[20..]),
        "credentialPublicKey": encode(&rest[..size]),
        "userHandle": encode(user_id.to_string().as_bytes()),
        "counter": data.counter,
        "backupEligible": data.flags & BACKUP_ELIGIBLE != 0,
        "backupStatus": data.flags & BACKUP_STATE != 0,
        "uvInitialized": data.flags & USER_VERIFIED != 0,
    });
    Ok((id, stored))
}

#[cfg(test)]
mod tests {
    use super::*;
    use ring::rand::SystemRandom;
    use ring::signature::{ECDSA_P256_SHA256_ASN1_SIGNING, EcdsaKeyPair, Ed25519KeyPair, KeyPair};

    const RP: &str = "example.com";
    const ORIGIN: &str = "https://example.com";

    /// RS256 made with `openssl dgst -sha256 -sign` over the message below.
    const RSA_N: &str = "bbdfcf22a3332803c0177a96bc1ea315b22a51b4a529031e7f232aca06e1526cab21d9d0487238c2eb444a5106128e7cd594623ed34aade38b0cbb452862d12532d4d697ff1d8a8fddf7af0d58f65130d1e093404da2972a9854eb606ebd2969cdffac1dce9bc6e879c85c4520bd4215970368a361a864dbe603b8af3a4a20d398e640abb491e0ec6d4baa4fa32b6fae2120ee81d30081da6d41f32e6be9c00aa543d55e8f6e1ffa368f6d886c54fd49af1a3d9b0e091ab3c1a287b0fe8490209c60654b3d4daf7a506214ef259a6a2c6ef8ea31541f0cf002d5c7a3ee414a8b901927915b6cb6903414e057249f29eae10a927a5d314db130ae67721e3b3529";
    const RSA_SIGNATURE: &str = "6f1a97a19edc76b84f9ab750c4df6ead765273bda82716c14c99f57541e1a95f95a0b67ca71d2dc3221712776602b0467d055dd112294226eb5532d476a5082b3b7b950bc34f5128e44827b43d5e47b014b22b644aa5db15e1d8fc2e03de0d1430075f143eebe5eb211fc2e334cc06877a8cbac040002e981076a056d25c9ed13ea8d7d14b48267047fd97817308be4223cb2d2c4d616c2c52c756f827c4cd06864d5c8f4d9ee7dfb5357fdefa265ab722017929b987f91d1b71bca86254e3ad459f1cd9f96c7be0bc13012af21ef81559387c89d7bb1c082fbaac13c955da6ba6ab9a4ebe6c2d156f8544e5d5a9ff56b7ba5f8e68864d84f05990b7ff634e44";
    const RSA_MESSAGE: &[u8] = b"passkey rs256 fixture";

    fn p256() -> (EcdsaKeyPair, Vec<u8>) {
        let random = SystemRandom::new();
        let pkcs8 = EcdsaKeyPair::generate_pkcs8(&ECDSA_P256_SHA256_ASN1_SIGNING, &random).unwrap();
        let pair =
            EcdsaKeyPair::from_pkcs8(&ECDSA_P256_SHA256_ASN1_SIGNING, pkcs8.as_ref(), &random)
                .unwrap();
        let point = pair.public_key().as_ref().to_vec();
        let mut cose = vec![0xa5, 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, 0x58, 0x20];
        cose.extend_from_slice(&point[1..33]);
        cose.extend_from_slice(&[0x22, 0x58, 0x20]);
        cose.extend_from_slice(&point[33..]);
        (pair, cose)
    }

    fn client(kind: &str, challenge: &str, origin: &str) -> Vec<u8> {
        json!({"type": kind, "challenge": challenge, "origin": origin})
            .to_string()
            .into_bytes()
    }

    fn data(flags: u8, counter: u32) -> Vec<u8> {
        let mut raw = Sha256::digest(RP.as_bytes()).to_vec();
        raw.push(flags);
        raw.extend_from_slice(&counter.to_be_bytes());
        raw
    }

    fn assertion(pair: &EcdsaKeyPair, client_data: &[u8], raw: &[u8]) -> Value {
        let mut message = raw.to_vec();
        message.extend_from_slice(&Sha256::digest(client_data));
        let signature = pair.sign(&SystemRandom::new(), &message).unwrap();
        json!({"rawId": "AQID", "type": "public-key", "response": {
            "clientDataJSON": encode(client_data),
            "authenticatorData": encode(raw),
            "signature": encode(signature.as_ref()),
            "userHandle": encode(b"7"),
        }})
    }

    fn stored(cose: &[u8], counter: u64) -> Value {
        json!({"credentialPublicKey": encode(cose), "userHandle": encode(b"7"), "counter": counter})
    }

    #[test]
    fn a_good_assertion_passes_and_moves_the_counter() {
        let (pair, cose) = p256();
        let credential = assertion(
            &pair,
            &client("webauthn.get", "abc", ORIGIN),
            &data(0x05, 9),
        );
        let updated = verify_assertion(&credential, &stored(&cose, 8), "abc", RP).unwrap();
        assert_eq!(updated["counter"], 9);
        assert_eq!(updated["uvInitialized"], true);
    }

    #[test]
    fn bad_assertions_fail() {
        let (pair, cose) = p256();
        let good = client("webauthn.get", "abc", ORIGIN);
        let check = |credential: &Value, counter: u64| {
            verify_assertion(credential, &stored(&cose, counter), "abc", RP)
        };
        let present = data(0x01, 9);
        assert!(
            check(
                &assertion(&pair, &client("webauthn.get", "other", ORIGIN), &present),
                0
            )
            .is_err()
        );
        assert!(
            check(
                &assertion(&pair, &client("webauthn.create", "abc", ORIGIN), &present),
                0
            )
            .is_err()
        );
        assert!(
            check(
                &assertion(
                    &pair,
                    &client("webauthn.get", "abc", "https://evil.example"),
                    &present
                ),
                0
            )
            .is_err()
        );
        assert!(
            check(&assertion(&pair, &good, &data(0x00, 9)), 0).is_err(),
            "user not present"
        );
        assert!(
            check(&assertion(&pair, &good, &present), 9).is_err(),
            "counter replay"
        );
        let mut foreign = Sha256::digest(b"evil.example").to_vec();
        foreign.extend_from_slice(&present[32..]);
        assert!(
            check(&assertion(&pair, &good, &foreign), 0).is_err(),
            "other relying party"
        );
        let (other, _) = p256();
        assert!(
            check(&assertion(&other, &good, &present), 0).is_err(),
            "other key"
        );
        let mut wrong_user = assertion(&pair, &good, &present);
        wrong_user["response"]["userHandle"] = json!(encode(b"8"));
        assert!(check(&wrong_user, 0).is_err(), "other user");
        assert!(
            verify_assertion(
                &assertion(&pair, &good, &present),
                &stored(&cose, 0),
                "",
                RP
            )
            .is_err()
        );
    }

    #[test]
    fn counters_of_zero_stay_allowed() {
        let (pair, cose) = p256();
        let credential = assertion(
            &pair,
            &client("webauthn.get", "abc", ORIGIN),
            &data(0x01, 0),
        );
        assert!(verify_assertion(&credential, &stored(&cose, 0), "abc", RP).is_ok());
    }

    #[test]
    fn ed25519_and_rsa_keys_verify() {
        let pkcs8 = Ed25519KeyPair::generate_pkcs8(&SystemRandom::new()).unwrap();
        let pair = Ed25519KeyPair::from_pkcs8(pkcs8.as_ref()).unwrap();
        let mut cose = vec![0xa4, 0x01, 0x01, 0x03, 0x27, 0x20, 0x06, 0x21, 0x58, 0x20];
        cose.extend_from_slice(pair.public_key().as_ref());
        assert!(verify_signature(&cose, b"hello", pair.sign(b"hello").as_ref()).is_ok());
        assert!(verify_signature(&cose, b"other", pair.sign(b"hello").as_ref()).is_err());

        let mut cose = vec![
            0xa4, 0x01, 0x03, 0x03, 0x39, 0x01, 0x00, 0x20, 0x59, 0x01, 0x00,
        ];
        cose.extend_from_slice(&hex::decode(RSA_N).unwrap());
        cose.extend_from_slice(&[0x21, 0x43, 0x01, 0x00, 0x01]);
        let signature = hex::decode(RSA_SIGNATURE).unwrap();
        assert!(verify_signature(&cose, RSA_MESSAGE, &signature).is_ok());
        assert!(verify_signature(&cose, b"other", &signature).is_err());
    }

    fn attestation(format: &str, flags: u8, cose: &[u8]) -> Vec<u8> {
        let mut raw = data(flags, 0);
        raw.extend_from_slice(&[0xaa; 16]);
        raw.extend_from_slice(&[0, 3, 1, 2, 3]);
        raw.extend_from_slice(cose);
        let mut object = vec![0xa3, 0x63];
        object.extend_from_slice(b"fmt");
        object.push(0x60 + format.len() as u8);
        object.extend_from_slice(format.as_bytes());
        object.push(0x67);
        object.extend_from_slice(b"attStmt");
        object.push(0xa0);
        object.push(0x68);
        object.extend_from_slice(b"authData");
        object.extend_from_slice(&[0x59, (raw.len() >> 8) as u8, raw.len() as u8]);
        object.extend_from_slice(&raw);
        object
    }

    fn registration(object: &[u8], kind: &str) -> Value {
        json!({"type": "public-key", "response": {
            "clientDataJSON": encode(&client(kind, "abc", ORIGIN)),
            "attestationObject": encode(object),
            "transports": ["internal"],
        }})
    }

    #[test]
    fn registration_stores_what_webauthn_lib_reads() {
        let (_, cose) = p256();
        let credential = registration(&attestation("none", 0x4d, &cose), "webauthn.create");
        let (id, data) = verify_registration(&credential, "abc", RP, 7).unwrap();
        assert_eq!(id, "AQID");
        assert_eq!(data["publicKeyCredentialId"], "AQID");
        assert_eq!(data["credentialPublicKey"], encode(&cose));
        assert_eq!(data["userHandle"], encode(b"7"));
        assert_eq!(data["aaguid"], "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa");
        assert_eq!(data["transports"], json!(["internal"]));
        assert_eq!(data["backupEligible"], true);
        assert_eq!(data["uvInitialized"], true);
    }

    #[test]
    fn bad_registrations_fail() {
        let (_, cose) = p256();
        let check = |credential: &Value| verify_registration(credential, "abc", RP, 7);
        assert!(
            check(&registration(
                &attestation("packed", 0x45, &cose),
                "webauthn.create"
            ))
            .is_err()
        );
        assert!(
            check(&registration(
                &attestation("none", 0x01, &cose),
                "webauthn.create"
            ))
            .is_err(),
            "no attested key"
        );
        assert!(
            check(&registration(
                &attestation("none", 0x45, &cose),
                "webauthn.get"
            ))
            .is_err()
        );
        assert!(
            check(&registration(
                &attestation("none", 0x45, &[0xa0]),
                "webauthn.create"
            ))
            .is_err(),
            "empty key"
        );
    }

    #[test]
    fn origins_match_the_relying_party_exactly() {
        assert!(origin_allowed("https://example.com", RP));
        assert!(origin_allowed("https://example.com:8443", RP));
        assert!(origin_allowed("http://localhost:8080", "localhost"));
        assert!(!origin_allowed("http://example.com", RP));
        assert!(!origin_allowed("https://example.com.evil.net", RP));
        assert!(!origin_allowed("https://sub.example.com", RP));
        assert!(!origin_allowed("https://example.com:x", RP));
        assert!(!origin_allowed("https://example.com/path", RP));
    }

    #[test]
    fn malformed_cbor_is_refused() {
        assert!(cbor(&[], 0).is_err());
        assert!(cbor(&[0x5b, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff], 0).is_err());
        assert!(cbor(&[0x9f], 0).is_err());
        assert!(cbor(&[0x81; 64], 0).is_err());
        assert_eq!(cbor(&[0x38, 0x18], 0).unwrap(), (Cbor::Int(-25), 2));
    }
}
