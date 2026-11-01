//! Parser output recorded from the php-final FFI library by
//! `fixtures/generate_metadata_golden.php`.

use nntmux_formats as formats;
use serde::Serialize;
use serde_json::Value;

fn golden() -> Value {
    serde_json::from_str(include_str!("fixtures/metadata-golden.json")).unwrap()
}

fn bytes(value: &Value) -> Vec<u8> {
    hex::decode(value.as_str().unwrap()).unwrap()
}

fn optional(value: &Value) -> Option<Vec<u8>> {
    (!value.is_null()).then(|| bytes(value))
}

fn code(value: &Value) -> Option<i32> {
    value.as_i64().map(|code| i32::try_from(code).unwrap())
}

fn check(actual: Option<impl Serialize>, case: &Value) {
    let expected: Value = serde_json::from_str(case["json"].as_str().unwrap()).unwrap();
    assert_eq!(
        serde_json::to_value(actual.unwrap()).unwrap(),
        expected,
        "{case}"
    );
}

#[test]
fn header_metadata_matches_the_library() {
    for case in golden()["headers"].as_array().unwrap() {
        check(formats::nntp_header_metadata(&bytes(&case["input"])), case);
    }
}

#[test]
fn article_dates_match_the_library() {
    for case in golden()["dates"].as_array().unwrap() {
        check(formats::article_dates(&bytes(&case["input"])), case);
    }
}

#[test]
fn header_comparisons_match_the_library() {
    let golden = golden();
    let cases = golden["pairs"].as_array().unwrap();
    assert!(cases.len() > 200);
    for case in cases {
        check(
            formats::nntp_header_comparison(
                &bytes(&case["left"]),
                &bytes(&case["right"]),
                case["identity"].as_bool(),
            ),
            case,
        );
    }
}

#[test]
fn body_measurements_match_the_library() {
    for case in golden()["bodies"].as_array().unwrap() {
        check(formats::nntp_body_measurement(&bytes(&case["input"])), case);
    }
}

#[test]
fn lines_relationships_match_the_library() {
    for case in golden()["relationships"].as_array().unwrap() {
        check(
            formats::nntp_lines_body_relationship(&bytes(&case["headers"]), &bytes(&case["body"])),
            case,
        );
    }
}

#[test]
fn article_identities_match_the_library() {
    let golden = golden();
    let cases = golden["identities"].as_array().unwrap();
    assert!(cases.len() > 1000);
    for case in cases {
        let (request, headers) = (bytes(&case["request"]), bytes(&case["headers"]));
        let (row, head, body) = (
            optional(&case["overview_row"]),
            optional(&case["head_text"]),
            optional(&case["body_text"]),
        );
        check(
            formats::article_identity(&formats::ArticleIdentityInput {
                requested_message_id: &request,
                headers: &headers,
                overview_row: row.as_deref(),
                overview_response_code: code(&case["overview_code"]),
                overview_attempted: case["attempted"].as_bool().unwrap(),
                head_response_text: head.as_deref(),
                body_response_text: body.as_deref(),
                head_response_code: code(&case["head_code"]),
                body_response_code: code(&case["body_code"]),
            }),
            case,
        );
    }
}

#[test]
fn article_xrefs_match_the_library() {
    let golden = golden();
    let cases = golden["xrefs"].as_array().unwrap();
    assert!(cases.len() > 50);
    for case in cases {
        let headers = bytes(&case["headers"]);
        let rows: Vec<Vec<u8>> = case["rows"].as_array().unwrap().iter().map(bytes).collect();
        let rows: Vec<&[u8]> = rows.iter().map(Vec::as_slice).collect();
        let format: Vec<Vec<u8>> = case["format"]
            .as_array()
            .unwrap()
            .iter()
            .map(bytes)
            .collect();
        let format: Vec<&[u8]> = format.iter().map(Vec::as_slice).collect();
        check(
            formats::article_xref(&formats::ArticleXrefInput {
                headers: &headers,
                overview_rows: &rows,
                overview_format: &format,
                head_response_code: code(&case["head_code"]),
                overview_response_code: code(&case["overview_code"]),
                overview_attempted: case["attempted"].as_bool().unwrap(),
                overview_row_count: i32::try_from(rows.len()).unwrap(),
                overview_format_response_code: code(&case["format_code"]),
            }),
            case,
        );
    }
}

#[test]
fn over_budget_inputs_are_refused() {
    let big = vec![b'x'; 131_073];
    assert!(formats::nntp_header_metadata(&big).is_none());
    assert!(formats::article_dates(&big[..65_537]).is_none());
    assert!(
        formats::article_identity(&formats::ArticleIdentityInput {
            headers: &big,
            ..Default::default()
        })
        .is_none()
    );
    assert!(formats::header_filename_matches(&big[..4_097], b"x", false).is_none());
}
