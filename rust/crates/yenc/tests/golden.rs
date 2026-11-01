//! Behaviour recorded from the php-final reference by `fixtures/generate_golden.php`.

use nntmux_yenc::framing;
use nntmux_yenc::{
    ArticleFrame, Fields, decode, decode_lenient, decode_with_frame, decoder, encode,
    extract_metadata, has_validated_integrity,
};
use serde_json::{Value, json};

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/golden.json")).unwrap()
}

fn fields_json(fields: &Fields) -> Value {
    Value::Object(
        fields
            .iter()
            .map(|(key, value)| (key.to_string(), json!(hex::encode(value))))
            .collect(),
    )
}

fn bytes(value: &Value) -> Vec<u8> {
    hex::decode(value.as_str().unwrap()).unwrap()
}

#[test]
fn frames_match_php() {
    for case in golden()["cases"].as_array().unwrap() {
        let name = &case["name"];
        let article = bytes(&case["article"]);
        let actual = ArticleFrame::parse(&article, true).map_or(Value::Null, |frame| {
            json!({
                "header": fields_json(&frame.header),
                "part": frame.part.as_ref().map(fields_json),
                "trailer": fields_json(&frame.trailer),
                "payload_offset": frame.payload_offset,
                "payload_length": frame.payload_length,
            })
        });
        assert_eq!(actual, case["frame"], "frame of {name}");
    }
}

#[test]
fn strict_decoding_matches_php() {
    for case in golden()["cases"].as_array().unwrap() {
        let name = &case["name"];
        let article = bytes(&case["article"]);
        let actual = match decode(&article) {
            Ok(decoded) => json!({"decoded": decoded.map(hex::encode), "error": null}),
            Err(error) => json!({"decoded": null, "error": error.to_string()}),
        };
        assert_eq!(actual, case["strict"], "strict decode of {name}");
    }
}

#[test]
fn lenient_decoding_matches_php() {
    for case in golden()["cases"].as_array().unwrap() {
        let name = &case["name"];
        let article = bytes(&case["article"]);
        assert_eq!(
            hex::encode(decode_lenient(&article)),
            case["lenient"].as_str().unwrap(),
            "lenient decode of {name}"
        );
        let (decoded, part) = decode_with_frame(&article);
        assert_eq!(
            hex::encode(&decoded),
            case["with_frame"].as_str().unwrap(),
            "frame decode of {name}"
        );
        let part = part.map_or(Value::Null, |part| {
            json!({
                "name": hex::encode(&part.name),
                "size": part.size,
                "part": part.part,
                "begin": part.begin,
                "end": part.end,
                "offset": part.offset(),
                "length": part.length(),
            })
        });
        assert_eq!(part, case["part"], "part of {name}");
        assert_eq!(
            has_validated_integrity(&article, &decoded),
            case["integrity_with_lenient"].as_bool().unwrap(),
            "integrity of {name}"
        );
    }
}

#[test]
fn metadata_matches_php() {
    for case in golden()["cases"].as_array().unwrap() {
        let article = bytes(&case["article"]);
        let actual = extract_metadata(&article).map_or(Value::Null, |metadata| {
            json!({
                "name": hex::encode(&metadata.name),
                "size": metadata.size,
                "line": metadata.line,
                "crc32": metadata.crc32.map(hex::encode),
            })
        });
        assert_eq!(actual, case["metadata"], "metadata of {}", case["name"]);
    }
}

#[test]
fn raw_kernels_match_the_ffi_library() {
    for case in golden()["cases"].as_array().unwrap() {
        if case["native_raw"].is_null() {
            continue;
        }
        let article = bytes(&case["article"]);
        let frame = ArticleFrame::parse(&article, true).unwrap();
        assert_eq!(
            hex::encode(decoder::decode(frame.payload(&article))),
            case["native_raw"].as_str().unwrap(),
            "kernel decode of {}",
            case["name"]
        );
    }
}

#[test]
fn framing_metadata_matches_the_ffi_library() {
    for case in golden()["cases"].as_array().unwrap() {
        let article = bytes(&case["article"]);
        let expected: Value = serde_json::from_str(case["framing"].as_str().unwrap()).unwrap();
        assert_eq!(
            serde_json::to_value(framing::parse(&article)).unwrap(),
            expected,
            "framing of {}",
            case["name"]
        );
    }
}

#[test]
fn encoding_matches_php() {
    for case in golden()["encodings"].as_array().unwrap() {
        let encoded = encode(
            &bytes(&case["data"]),
            &bytes(&case["filename"]),
            case["line_length"].as_i64().unwrap(),
            case["include_crc32"].as_bool().unwrap(),
        )
        .unwrap();
        assert_eq!(hex::encode(encoded), case["encoded"].as_str().unwrap());
    }
}

#[test]
fn encoder_rejects_what_php_rejects() {
    assert_eq!(
        encode(b"ABC", b"name\n=yend", 128, true)
            .unwrap_err()
            .to_string(),
        "A yEnc filename cannot contain line endings."
    );
    assert_eq!(
        encode(b"data", b"test.txt", 0, true)
            .unwrap_err()
            .to_string(),
        "0 is not a valid line length."
    );
}

#[test]
fn in_place_and_buffer_decoding_agree_with_the_allocating_path() {
    let article = encode(&[0, 1, 2, 214, 224, 227, 19, 42], b"x", 3, false).unwrap();
    let frame = ArticleFrame::parse(&article, true).unwrap();
    let payload = frame.payload(&article);
    let expected = decoder::decode(payload);
    let mut buffer = vec![0xcc; payload.len() + 2];
    let written = decoder::decode_into(payload, &mut buffer);
    assert_eq!(buffer[..written], expected);
    assert!(buffer[written..].iter().all(|byte| *byte == 0xcc));
    let mut in_place = payload.to_vec();
    let written = decoder::decode_in_place(&mut in_place);
    assert_eq!(in_place[..written], expected);
}

#[test]
fn an_unmeasurable_lenient_range_has_no_length() {
    let article =
        b"=ybegin part=1 line=1 size=1 name=x\n=ypart begin=0 end=9223372036854775807\nk\n=yend size=1";
    let (_, part) = decode_with_frame(article);
    assert_eq!(part.unwrap().length(), None);
}
