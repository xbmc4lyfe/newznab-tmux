//! Passkey registration and sign-in with a software authenticator, against real MariaDB and
//! Redis. Skips itself when the services aren't set.

mod common;

use axum::http::StatusCode;
use base64::Engine;
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use common::*;
use ring::rand::SystemRandom;
use ring::signature::{ECDSA_P256_SHA256_ASN1_SIGNING, EcdsaKeyPair, KeyPair};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

const ORIGIN: &str = "http://localhost:8080";

fn encode(bytes: &[u8]) -> String {
    URL_SAFE_NO_PAD.encode(bytes)
}

struct Authenticator {
    pair: EcdsaKeyPair,
    id: Vec<u8>,
    counter: u32,
}

impl Authenticator {
    fn new() -> Self {
        let random = SystemRandom::new();
        let pkcs8 = EcdsaKeyPair::generate_pkcs8(&ECDSA_P256_SHA256_ASN1_SIGNING, &random).unwrap();
        let pair =
            EcdsaKeyPair::from_pkcs8(&ECDSA_P256_SHA256_ASN1_SIGNING, pkcs8.as_ref(), &random)
                .unwrap();
        Self {
            pair,
            id: rand::random::<[u8; 20]>().to_vec(),
            counter: 0,
        }
    }

    fn cose(&self) -> Vec<u8> {
        let point = self.pair.public_key().as_ref();
        let mut cose = vec![0xa5, 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, 0x58, 0x20];
        cose.extend_from_slice(&point[1..33]);
        cose.extend_from_slice(&[0x22, 0x58, 0x20]);
        cose.extend_from_slice(&point[33..]);
        cose
    }

    fn auth_data(&self, flags: u8) -> Vec<u8> {
        let mut data = Sha256::digest(b"localhost").to_vec();
        data.push(flags);
        data.extend_from_slice(&self.counter.to_be_bytes());
        data
    }

    fn register(&self, challenge: &str) -> String {
        let mut data = self.auth_data(0x45);
        data.extend_from_slice(&[0; 16]);
        data.extend_from_slice(&(self.id.len() as u16).to_be_bytes());
        data.extend_from_slice(&self.id);
        data.extend_from_slice(&self.cose());
        let mut object = vec![0xa3, 0x63];
        object.extend_from_slice(b"fmt");
        object.push(0x64);
        object.extend_from_slice(b"none");
        object.push(0x67);
        object.extend_from_slice(b"attStmt");
        object.push(0xa0);
        object.push(0x68);
        object.extend_from_slice(b"authData");
        object.extend_from_slice(&[0x59, (data.len() >> 8) as u8, data.len() as u8]);
        object.extend_from_slice(&data);
        let client = json!({"type": "webauthn.create", "challenge": challenge, "origin": ORIGIN})
            .to_string();
        json!({
            "id": encode(&self.id),
            "rawId": encode(&self.id),
            "type": "public-key",
            "response": {"clientDataJSON": encode(client.as_bytes()), "attestationObject": encode(&object), "transports": ["internal"]},
        })
        .to_string()
    }

    fn sign_in(&mut self, challenge: &str, user: i64) -> String {
        self.counter += 1;
        let data = self.auth_data(0x05);
        let client =
            json!({"type": "webauthn.get", "challenge": challenge, "origin": ORIGIN}).to_string();
        let mut message = data.clone();
        message.extend_from_slice(&Sha256::digest(client.as_bytes()));
        let signature = self.pair.sign(&SystemRandom::new(), &message).unwrap();
        json!({
            "id": encode(&self.id),
            "rawId": encode(&self.id),
            "type": "public-key",
            "response": {
                "clientDataJSON": encode(client.as_bytes()),
                "authenticatorData": encode(&data),
                "signature": encode(signature.as_ref()),
                "userHandle": encode(user.to_string().as_bytes()),
            },
        })
        .to_string()
    }
}

async fn challenge(browser: &mut Browser) -> String {
    let (response, body) = browser.get("/passkeys/authentication-options").await;
    assert_eq!(response.status(), StatusCode::OK);
    let options: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(options["rpId"], "localhost");
    options["challenge"].as_str().unwrap().to_owned()
}

#[tokio::test]
async fn register_then_sign_in_then_delete() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("pia");
    let id = add_user(&db.pool, &name, true, 1).await;
    let mut browser = Browser::new(&ctx);
    browser
        .post("/login", &[("username", &name), ("password", PASSWORD)])
        .await;

    // Managing passkeys needs a fresh password confirmation.
    let (response, _) = browser
        .post_authed("/passkeys/register-options", &[("name", "Laptop")])
        .await;
    assert_eq!(response.status(), StatusCode::LOCKED);
    browser
        .post_authed("/confirm-password", &[("password", PASSWORD)])
        .await;

    let (response, _) = browser.post_authed("/passkeys/register-options", &[]).await;
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);
    let (response, body) = browser
        .post_authed("/passkeys/register-options", &[("name", "Laptop")])
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    let options: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(options["options"]["rp"]["id"], "localhost");
    assert_eq!(
        options["options"]["user"]["id"],
        encode(id.to_string().as_bytes())
    );
    let registration_challenge = options["options"]["challenge"].as_str().unwrap().to_owned();

    let mut authenticator = Authenticator::new();
    let (response, body) = browser
        .post_authed(
            "/passkeys",
            &[
                ("name", "Laptop"),
                ("passkey", &authenticator.register("wrong")),
            ],
        )
        .await;
    assert_eq!(
        response.status(),
        StatusCode::UNPROCESSABLE_ENTITY,
        "{body}"
    );

    // A failed try used up the challenge, as PHP's session pull did.
    let (_, body) = browser
        .post_authed("/passkeys/register-options", &[("name", "Laptop")])
        .await;
    let options: Value = serde_json::from_str(&body).unwrap();
    let fresh = options["options"]["challenge"].as_str().unwrap().to_owned();
    assert_ne!(fresh, registration_challenge);
    let (response, body) = browser
        .post_authed(
            "/passkeys",
            &[
                ("name", "Laptop"),
                ("passkey", &authenticator.register(&fresh)),
            ],
        )
        .await;
    assert_eq!(response.status(), StatusCode::OK, "{body}");
    let stored: Value = serde_json::from_str(&body).unwrap();
    assert_eq!(stored["ok"], true);
    let passkey = stored["passkey"]["id"].as_i64().unwrap();

    // Sign in on a new browser, without a password.
    let mut fresh_browser = Browser::new(&ctx);
    let sign_in_challenge = challenge(&mut fresh_browser).await;
    let response_json = authenticator.sign_in(&sign_in_challenge, id);
    let (response, _) = fresh_browser
        .post(
            "/passkeys/authenticate",
            &[
                ("start_authentication_response", &response_json),
                ("remember", "1"),
            ],
        )
        .await;
    assert_eq!(location(&response), "/");
    assert!(browser_is_signed_in(&mut fresh_browser).await);
    let (counter, used): (Option<String>, i64) = sqlx::query_as(
        "SELECT JSON_VALUE(data, '$.counter'), CAST(last_used_at IS NOT NULL AS SIGNED) FROM passkeys WHERE id = ?",
    )
    .bind(passkey)
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(counter.as_deref(), Some("1"));
    assert_eq!(used, 1);

    // The same signed response can't sign in twice.
    let mut replay = Browser::new(&ctx);
    let (response, _) = replay
        .post(
            "/passkeys/authenticate",
            &[("start_authentication_response", &response_json)],
        )
        .await;
    assert_eq!(location(&response), "/login");
    assert!(!browser_is_signed_in(&mut replay).await);

    // Someone else's passkey can't be deleted.
    let other = unique("ota");
    add_user(&db.pool, &other, true, 1).await;
    let mut stranger = Browser::new(&ctx);
    stranger
        .post("/login", &[("username", &other), ("password", PASSWORD)])
        .await;
    stranger
        .post_authed("/confirm-password", &[("password", PASSWORD)])
        .await;
    let token_page = stranger.get("/confirm-password").await.1;
    let request = axum::http::Request::delete(format!("/passkeys/{passkey}"))
        .header("accept", "application/json")
        .header("x-csrf-token", extract_token(&token_page));
    let (_, body) = stranger.send(request, String::new()).await;
    assert_eq!(serde_json::from_str::<Value>(&body).unwrap()["ok"], false);

    // Signing in elsewhere ended the first browser's session, so the new one deletes it.
    fresh_browser
        .post_authed("/confirm-password", &[("password", PASSWORD)])
        .await;
    let token_page = fresh_browser.get("/confirm-password").await.1;
    let request = axum::http::Request::delete(format!("/passkeys/{passkey}"))
        .header("accept", "application/json")
        .header("x-csrf-token", extract_token(&token_page));
    let (_, body) = fresh_browser.send(request, String::new()).await;
    assert_eq!(serde_json::from_str::<Value>(&body).unwrap()["ok"], true);
}

/// The row shape PHP's webauthn-lib wrote, with the mangled `credential_id` it stored.
#[tokio::test]
async fn a_passkey_registered_under_php_still_signs_in() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let name = unique("phil");
    let id = add_user(&db.pool, &name, true, 1).await;
    let mut authenticator = Authenticator::new();
    let data = json!({
        "publicKeyCredentialId": encode(&authenticator.id),
        "type": "public-key",
        "transports": ["internal", "hybrid"],
        "attestationType": "none",
        "trustPath": {"type": "Webauthn\\TrustPath\\EmptyTrustPath"},
        "aaguid": "00000000-0000-0000-0000-000000000000",
        "credentialPublicKey": encode(&authenticator.cose()),
        "userHandle": encode(id.to_string().as_bytes()),
        "counter": 0,
        "backupEligible": true,
        "backupStatus": true,
        "uvInitialized": true,
    });
    sqlx::query("INSERT INTO passkeys (authenticatable_id, name, credential_id, data, created_at, updated_at) VALUES (?, 'Old', '??mangled??', ?, NOW(), NOW())")
        .bind(id)
        .bind(data.to_string())
        .execute(&db.pool)
        .await
        .unwrap();

    let mut browser = Browser::new(&ctx);
    let sign_in_challenge = challenge(&mut browser).await;
    let signed = authenticator.sign_in(&sign_in_challenge, id);
    let (response, _) = browser
        .post(
            "/passkeys/authenticate",
            &[("start_authentication_response", &signed)],
        )
        .await;
    assert_eq!(location(&response), "/");
    assert!(browser_is_signed_in(&mut browser).await);
    let aaguid: Option<String> = sqlx::query_scalar(
        "SELECT JSON_VALUE(data, '$.aaguid') FROM passkeys WHERE authenticatable_id = ?",
    )
    .bind(id)
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(
        aaguid.as_deref(),
        Some("00000000-0000-0000-0000-000000000000"),
        "other fields survive the update"
    );

    // A disabled account doesn't get in, even with a good signature.
    sqlx::query("UPDATE users SET verified = 0, email_verified_at = NULL WHERE id = ?")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let mut blocked = Browser::new(&ctx);
    let sign_in_challenge = challenge(&mut blocked).await;
    let signed = authenticator.sign_in(&sign_in_challenge, id);
    let (response, _) = blocked
        .post(
            "/passkeys/authenticate",
            &[("start_authentication_response", &signed)],
        )
        .await;
    assert_eq!(location(&response), "/login");
    assert!(!browser_is_signed_in(&mut blocked).await);

    // No challenge in the session is refused without looking at the response.
    let mut no_session = Browser::new(&ctx);
    let (response, _) = no_session
        .post(
            "/passkeys/authenticate",
            &[("start_authentication_response", "{}")],
        )
        .await;
    assert_eq!(location(&response), "/login");
}
