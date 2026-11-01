//! The PAR2 accumulator, payload budgets and size helpers against the real PHP classes.
//! `fixtures/generate_par2_golden.php` built each packet stream with real checksums, fed it to
//! `Par2PacketAccumulator` chunk by chunk and recorded every observable result.

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD;
use nntmux_namefix::par2_accumulator::{AccumulatorError, PacketAccumulator, SetFile};
use nntmux_namefix::par2_budget::{exceeds_payload_budget, set_fits_payload_budget, size_decimal};
use serde_json::Value;

fn fixture() -> Value {
    serde_json::from_str(include_str!("fixtures/par2_golden.json")).expect("fixture")
}

fn error_name(error: &AccumulatorError) -> &'static str {
    match error {
        AccumulatorError::Invalid(_) => "InvalidArgumentException",
        AccumulatorError::Length(_) => "LengthException",
    }
}

fn expected_bool(value: &Value) -> Result<bool, String> {
    match value.get("ok") {
        Some(ok) => Ok(ok.as_bool().expect("bool")),
        None => Err(value["error"].as_str().expect("error").to_owned()),
    }
}

fn outcome(result: Result<bool, AccumulatorError>) -> Result<bool, String> {
    result.map_err(|e| error_name(&e).to_owned())
}

#[test]
fn accumulator_matches_php() {
    let fixture = fixture();
    for scenario in fixture["scenarios"].as_array().unwrap() {
        let name = scenario["name"].as_str().unwrap();
        let max = scenario["max"].as_u64().unwrap() as usize;
        let acc = PacketAccumulator::new(max);
        if let Some(expected) = scenario.get("constructor_error") {
            assert_eq!(
                error_name(&acc.unwrap_err()),
                expected.as_str().unwrap(),
                "{name}"
            );
            continue;
        }
        let mut acc = acc.unwrap_or_else(|e| panic!("{name}: {e}"));
        for (index, (chunk, step)) in scenario["chunks"]
            .as_array()
            .unwrap()
            .iter()
            .zip(scenario["steps"].as_array().unwrap())
            .enumerate()
        {
            let bytes = STANDARD.decode(chunk.as_str().unwrap()).unwrap();
            let result = acc.append(&bytes);
            let label = format!("{name} step {index}");
            match (&result, step.get("error")) {
                (Ok(()), None) => {}
                (Err(e), Some(expected)) => {
                    assert_eq!(error_name(e), expected.as_str().unwrap(), "{label}")
                }
                other => panic!("{label}: {other:?} vs {step}"),
            }
            assert_eq!(acc.state_json(), step["state"].as_str().unwrap(), "{label}");
            assert_eq!(
                acc.state_bytes() as u64,
                step["bytes"].as_u64().unwrap(),
                "{label} bytes"
            );
        }
        assert_eq!(
            outcome(acc.is_complete()),
            expected_bool(&scenario["is_complete"]),
            "{name} complete"
        );
        assert_eq!(
            outcome(acc.has_complete_main_inventory()),
            expected_bool(&scenario["main_complete"]),
            "{name} main"
        );
        files_match(name, &acc, scenario);
        descriptors_match(name, &acc, scenario);
        assert_eq!(
            outcome(
                PacketAccumulator::from_state(
                    &serde_json::from_str(&acc.state_json()).unwrap(),
                    max
                )
                .map(|round| round.state_json() == acc.state_json())
            ),
            expected_bool(&scenario["from_state"]),
            "{name} round trip"
        );
        for budget in scenario["budgets"].as_array().unwrap() {
            let payload = budget["payload"].as_i64();
            assert_eq!(
                outcome(exceeds_payload_budget(&acc, payload)),
                expected_bool(&budget["exceeds"]),
                "{name} budget {payload:?}"
            );
        }
    }
}

fn files_match(name: &str, acc: &PacketAccumulator, scenario: &Value) {
    let expected = &scenario["files_by_set"];
    let actual = acc.files_by_set();
    if expected.get("error").is_some() {
        assert!(actual.is_err(), "{name}: files_by_set should fail");
        return;
    }
    let actual = actual.unwrap_or_else(|e| panic!("{name}: {e}"));
    let expected = expected["ok"].as_array().unwrap();
    assert_eq!(actual.len(), expected.len(), "{name} sets");
    for ((set, files), want) in actual.iter().zip(expected) {
        assert_eq!(set, want["set"].as_str().unwrap(), "{name} set id");
        let wanted = want["files"].as_array().unwrap();
        assert_eq!(files.len(), wanted.len(), "{name} file count");
        for (file, want) in files.iter().zip(wanted) {
            let text = |key: &str| want[key].as_str().map(str::to_owned);
            let bytes = want["name"].as_str().map(|n| STANDARD.decode(n).unwrap());
            assert_eq!(file.name, bytes, "{name} name");
            assert_eq!(file.size_decimal, text("size_decimal"), "{name} decimal");
            assert_eq!(file.hash, text("hash"), "{name} hash");
            assert_eq!(file.hash_16k, text("hash_16K"), "{name} hash16k");
            if !want["size_float"].as_bool().unwrap() {
                assert_eq!(
                    file.size.map(|s| s.to_string()),
                    text("size"),
                    "{name} size"
                );
            }
            assert_eq!(
                file.blocks as u64,
                want["blocks"].as_u64().unwrap_or(0),
                "{name} blocks"
            );
        }
    }
}

fn descriptors_match(name: &str, acc: &PacketAccumulator, scenario: &Value) {
    let expected = scenario["descriptors"]["ok"].as_array().unwrap();
    let actual = acc.descriptors();
    assert_eq!(actual.len(), expected.len(), "{name} descriptors");
    for (got, want) in actual.iter().zip(expected) {
        let text = |key: &str| want[key].as_str().unwrap().to_owned();
        assert_eq!(got.recovery_set_id, text("recovery_set_id"), "{name}");
        assert_eq!(got.file_id, text("file_id"), "{name}");
        assert_eq!(got.hash, text("hash"), "{name}");
        assert_eq!(got.hash_16k, text("hash_16K"), "{name}");
        assert_eq!(got.size_decimal, text("size_decimal"), "{name}");
        assert_eq!(got.packet_checksum, text("packet_checksum"), "{name}");
        assert_eq!(
            STANDARD.encode(&got.filename_bytes),
            text("filename_b64"),
            "{name}"
        );
    }
}

#[test]
fn stored_state_validation_matches_php() {
    for case in fixture()["states"].as_array().unwrap() {
        let name = case["name"].as_str().unwrap();
        let state: Value = serde_json::from_str(case["state"].as_str().unwrap()).unwrap();
        let result = PacketAccumulator::from_state(&state, 262_144);
        match case["result"].get("ok") {
            Some(canonical) => {
                let round = result.unwrap_or_else(|e| panic!("{name}: {e}"));
                assert_eq!(round.state_json(), canonical.as_str().unwrap(), "{name}");
            }
            None => assert_eq!(
                error_name(&result.err().unwrap_or_else(|| panic!("{name} should fail"))),
                case["result"]["error"].as_str().unwrap(),
                "{name}"
            ),
        }
    }
}

#[test]
fn size_helpers_match_php() {
    let fixture = fixture();
    for case in fixture["sizes"].as_array().unwrap() {
        let input = &case["input"];
        // Only strings reach this function in Rust; integers are canonical by type.
        let actual = match input {
            Value::String(text) => size_decimal(text),
            Value::Number(number) if number.is_u64() => size_decimal(&number.to_string()),
            Value::Number(number) => size_decimal(&number.to_string()).filter(|_| false),
            _ => None,
        };
        assert_eq!(actual.as_deref(), case["result"].as_str(), "{input}");
    }
    for case in fixture["little_endian"].as_array().unwrap() {
        let bytes = STANDARD
            .decode(case["bytes_b64"].as_str().unwrap())
            .unwrap();
        assert_eq!(
            nntmux_namefix::par2_accumulator::little_endian_size(&bytes).to_string(),
            case["result"].as_str().unwrap()
        );
    }
}

#[test]
fn set_payload_budget_matches_php() {
    for case in fixture()["fits"].as_array().unwrap() {
        let files: Vec<SetFile> = case["files"]
            .as_array()
            .unwrap()
            .iter()
            .map(|file| SetFile {
                name: file["name"].as_str().map(|n| n.as_bytes().to_vec()),
                size_decimal: file["size_decimal"].as_str().map(str::to_owned),
                ..SetFile::default()
            })
            .collect();
        let max = case["max"].as_i64();
        assert_eq!(
            set_fits_payload_budget(&files, max),
            case["fits"].as_bool().unwrap(),
            "{} {max:?}",
            case["files"]
        );
    }
}
