use std::{ffi::OsString, fs};

fn random(state: &mut u32) -> u32 {
    *state ^= *state << 13;
    *state ^= *state >> 17;
    *state ^= *state << 5;
    *state
}

fn median(values: &mut [f64]) -> f64 {
    // Stable numeric ordering preserves the input order of signed-zero ties.
    values.sort_by(|left, right| left.partial_cmp(right).unwrap());
    let middle = values.len() / 2;
    if values.len().is_multiple_of(2) {
        (values[middle - 1] + values[middle]) / 2.0
    } else {
        values[middle]
    }
}

struct Summary {
    decision: u32,
    eligible: usize,
    family: i64,
    median: f64,
    lower: f64,
    upper: f64,
}

fn process(input: &[u8]) -> Result<Summary, String> {
    if input.len() < 56 || !(input.len() - 32).is_multiple_of(8) {
        return Err("expected 32-byte header and at least three little-endian f64 ratios".into());
    }
    let seed = i64::from_le_bytes(input[0..8].try_into().unwrap());
    let family = i64::from_le_bytes(input[8..16].try_into().unwrap());
    let improvement = f64::from_le_bytes(input[16..24].try_into().unwrap());
    let regression = f64::from_le_bytes(input[24..32].try_into().unwrap());
    let mut ratios: Vec<f64> = input[32..]
        .as_chunks::<8>()
        .0
        .iter()
        .map(|bytes| f64::from_le_bytes(*bytes))
        .collect();
    if ratios.iter().any(|ratio| !ratio.is_finite()) {
        return Err("expected finite matched ratios".into());
    }
    // PHP substitutes one only for the original integer zero, before masking.
    let mut state = if seed == 0 { 1 } else { seed as u32 };
    let mut medians = Vec::with_capacity(10000);
    let mut resample = Vec::with_capacity(ratios.len());
    for _ in 0..10000 {
        resample.clear();
        for _ in 0..ratios.len() {
            resample.push(ratios[random(&mut state) as usize % ratios.len()]);
        }
        medians.push(median(&mut resample));
    }
    medians.sort_by(|left, right| left.partial_cmp(right).unwrap());
    let tail = 0.025 / family.max(1) as f64;
    let lower = medians[(10000.0 * tail).floor() as usize];
    let upper = medians[(10000.0 * (1.0 - tail)).ceil() as usize - 1];
    let decision: u32 = if lower > 1.0 + regression / 100.0 {
        0
    } else if upper < 1.0 - improvement / 100.0 {
        1
    } else {
        2
    };
    Ok(Summary {
        decision,
        eligible: ratios.len(),
        family,
        median: median(&mut ratios),
        lower,
        upper,
    })
}

fn json_number(value: f64) -> String {
    // PHP json_decode preserves overflow as infinity, as the original helper did.
    if value == f64::INFINITY {
        "1e999".into()
    } else if value == f64::NEG_INFINITY {
        "-1e999".into()
    } else {
        format!("{value:?}")
    }
}

fn render(input: &[u8]) -> Result<String, String> {
    let result = process(input)?;
    let decision = ["regression", "useful_improvement", "inconclusive"][result.decision as usize];
    Ok(format!(
        "{{\"status\":\"ok\",\"result\":{{\"decision\":\"{decision}\",\"eligible_pairs\":{},\"invalid_pairs\":0,\"median\":{},\"bootstrap_95_percent_interval\":[{},{}],\"bootstrap_replicates\":10000,\"confidence_family_size\":{},\"confidence_scope\":\"Bonferroni simultaneous 95 percent intervals across the predeclared stratum family\"}}}}",
        result.eligible,
        json_number(result.median),
        json_number(result.lower),
        json_number(result.upper),
        result.family
    ))
}

fn run(mut args: impl Iterator<Item = OsString>) -> Result<String, String> {
    let usage = "Usage: nntmux-yenc-bootstrap <input-file>";
    let path = args.next().ok_or(usage)?;
    if args.next().is_some() {
        return Err(usage.into());
    }
    let input = fs::read(&path).map_err(|error| format!("unable to read input file: {error}"))?;
    render(&input)
}

fn main() {
    match run(std::env::args_os().skip(1)) {
        Ok(output) => println!("{output}"),
        Err(error) => {
            eprintln!("yEnc bootstrap: {error}");
            std::process::exit(1);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};

    fn hex(text: &str) -> Vec<u8> {
        text.as_bytes()
            .as_chunks::<2>()
            .0
            .iter()
            .map(|pair| u8::from_str_radix(std::str::from_utf8(pair).unwrap(), 16).unwrap())
            .collect()
    }

    fn input(ratios: &[f64], family: i64) -> Vec<u8> {
        let mut bytes = Vec::new();
        bytes.extend_from_slice(&123i64.to_le_bytes());
        bytes.extend_from_slice(&family.to_le_bytes());
        bytes.extend_from_slice(&5.0f64.to_le_bytes());
        bytes.extend_from_slice(&5.0f64.to_le_bytes());
        for ratio in ratios {
            bytes.extend_from_slice(&ratio.to_le_bytes());
        }
        bytes
    }

    fn packed(result: &Summary) -> Vec<u8> {
        let mut bytes = Vec::with_capacity(28);
        bytes.extend_from_slice(&result.decision.to_le_bytes());
        for value in [result.median, result.lower, result.upper] {
            bytes.extend_from_slice(&value.to_le_bytes());
        }
        bytes
    }

    // Frozen outputs from the original vendor-free PHP helper, packed as Veee.
    macro_rules! golden {
        ($name:ident, $input:literal, $expected:literal) => {
            #[test]
            fn $name() {
                assert_eq!(packed(&process(&hex($input)).unwrap()), hex($expected));
            }
        };
    }
    golden!(
        both_inf,
        "7b000000000000000100000000000000000000000000f07f000000000000f07f9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "020000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        both_nan,
        "7b000000000000000100000000000000000000000000f87f000000000000f87f9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "020000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        both_neg_inf,
        "7b000000000000000100000000000000000000000000f0ff000000000000f0ff9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "000000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        ci,
        "7b000000000000000600000000000000000000000000144000000000000014409a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "010000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        decision_order,
        "7b00000000000000010000000000000000000000000059c000000000000059c0000000000000f03f000000000000f03f000000000000f03f",
        "00000000000000000000f03f000000000000f03f000000000000f03f"
    );
    golden!(
        default,
        "95283501000000001800000000000000000000000000144000000000000014409a9999999999d93f9a9999999999e13f333333333333e33fcdcccccccccce43f666666666666e63f9a9999999999e93f333333333333eb3f295c8fc2f528ec3fcdccccccccccec3f14ae47e17a14ee3f9a9999999999f13f",
        "010000009a9999999999e93f9a9999999999e13f14ae47e17a14ee3f"
    );
    golden!(
        even,
        "ffffffffffffffff0600000000000000000000000000144000000000000014409a9999999999e93fcdccccccccccec3f000000000000f03f9a9999999999f13f",
        "02000000666666666666ee3f9a9999999999e93f9a9999999999f13f"
    );
    golden!(
        family_max,
        "ffffffffffffff7fffffffffffffff7f00000000000014400000000000001440666666666666e63fcdccccccccccec3f000000000000f03f9a9999999999f13fcdccccccccccf43f",
        "02000000000000000000f03f666666666666e63fcdccccccccccf43f"
    );
    golden!(
        family_negative,
        "0000000000000080fbffffffffffffff00000000000014400000000000001440666666666666e63fcdccccccccccec3f000000000000f03f9a9999999999f13fcdccccccccccf43f",
        "01000000000000000000f03f666666666666e63f666666666666e63f"
    );
    golden!(
        family_zero,
        "7b00000000000000000000000000000000000000000014400000000000001440666666666666e63fcdccccccccccec3f000000000000f03f9a9999999999f13fcdccccccccccf43f",
        "02000000000000000000f03f666666666666e63fcdccccccccccf43f"
    );
    golden!(
        improvement_inf,
        "7b000000000000000100000000000000000000000000f07f00000000000014409a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "020000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        improvement_nan,
        "7b000000000000000100000000000000000000000000f87f00000000000014409a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "020000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        improvement_neg_inf,
        "7b000000000000000100000000000000000000000000f0ff00000000000014409a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "010000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        odd,
        "7b000000000000000100000000000000000000000000144000000000000014401f85eb51b81eed3f713d0ad7a370ed3fc3f5285c8fc2ed3f14ae47e17a14ee3fb81e85eb51b8ee3f",
        "02000000c3f5285c8fc2ed3f1f85eb51b81eed3fb81e85eb51b8ee3f"
    );
    golden!(
        overflow,
        "7b00000000000000010000000000000000000000000014400000000000001440ffffffffffffef7fffffffffffffef7fffffffffffffef7fffffffffffffef7f",
        "00000000000000000000f07f000000000000f07f000000000000f07f"
    );
    golden!(
        regression,
        "7b00000000000000010000000000000000000000000014400000000000001440333333333333f33f333333333333f33f333333333333f33f",
        "00000000333333333333f33f333333333333f33f333333333333f33f"
    );
    golden!(
        regression_inf,
        "7b0000000000000001000000000000000000000000001440000000000000f07f333333333333f33f333333333333f33f333333333333f33f",
        "02000000333333333333f33f333333333333f33f333333333333f33f"
    );
    golden!(
        regression_nan,
        "7b0000000000000001000000000000000000000000001440000000000000f87f9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "010000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        regression_nan_improvement_neg_inf,
        "7b000000000000000100000000000000000000000000f0ff000000000000f87f9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "010000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        regression_neg_inf,
        "7b0000000000000001000000000000000000000000001440000000000000f0ff9a9999999999e93f9a9999999999e93f9a9999999999e93f",
        "000000009a9999999999e93f9a9999999999e93f9a9999999999e93f"
    );
    golden!(
        seed_low_zero,
        "00000000010000000100000000000000000000000000144000000000000014409a9999999999b93f000000000000e03fcdccccccccccec3f",
        "01000000000000000000e03f9a9999999999b93f9a9999999999b93f"
    );
    golden!(
        seed_zero,
        "00000000000000000100000000000000000000000000144000000000000014409a9999999999b93f000000000000e03fcdccccccccccec3f",
        "01000000000000000000e03f9a9999999999b93fcdccccccccccec3f"
    );
    golden!(
        signed_zero,
        "7b000000000000000100000000000000000000000000144000000000000014400000000000000080000000000000000000000000000000800000000000000000",
        "01000000000000000000000000000000000000000000000000000000"
    );
    golden!(
        strict_lower,
        "7b00000000000000010000000000000000000000000014400000000000001440cdccccccccccf03fcdccccccccccf03fcdccccccccccf03fcdccccccccccf03f",
        "02000000cdccccccccccf03fcdccccccccccf03fcdccccccccccf03f"
    );
    golden!(
        strict_upper,
        "7b00000000000000010000000000000000000000000014400000000000001440666666666666ee3f666666666666ee3f666666666666ee3f666666666666ee3f",
        "02000000666666666666ee3f666666666666ee3f666666666666ee3f"
    );
    golden!(
        underflow,
        "7b00000000000000010000000000000000000000000014400000000000001440ffffffffffffefffffffffffffffefffffffffffffffefffffffffffffffefff",
        "01000000000000000000f0ff000000000000f0ff000000000000f0ff"
    );

    #[test]
    fn renders_the_exact_ordered_result_envelope() {
        let result = render(&input(&[0.8; 3], 6)).unwrap();
        assert_eq!(
            result,
            r#"{"status":"ok","result":{"decision":"useful_improvement","eligible_pairs":3,"invalid_pairs":0,"median":0.8,"bootstrap_95_percent_interval":[0.8,0.8],"bootstrap_replicates":10000,"confidence_family_size":6,"confidence_scope":"Bonferroni simultaneous 95 percent intervals across the predeclared stratum family"}}"#
        );
        let parsed: serde_json::Value = serde_json::from_str(&result).unwrap();
        assert_eq!(parsed["result"]["eligible_pairs"], 3);
    }

    #[test]
    fn emits_overflow_as_json_numeric_tokens_accepted_by_php() {
        for (ratio, token) in [(f64::MAX, "1e999"), (-f64::MAX, "-1e999")] {
            let result = render(&input(&[ratio; 4], 1)).unwrap();
            assert!(result.contains(&format!("\"median\":{token}")));
            assert!(result.contains(&format!(
                "\"bootstrap_95_percent_interval\":[{token},{token}]"
            )));
        }
    }

    #[test]
    fn preserves_negative_zero_and_integral_float_json_types() {
        assert!(
            render(&input(&[-0.0; 3], 1))
                .unwrap()
                .contains("\"median\":-0.0")
        );
        assert!(
            render(&input(&[1.0; 3], 1))
                .unwrap()
                .contains("\"median\":1.0")
        );
    }

    struct Fixture(std::path::PathBuf);
    impl Fixture {
        fn new() -> Self {
            static NEXT: AtomicUsize = AtomicUsize::new(0);
            Self(std::env::temp_dir().join(format!(
                "nntmux-bootstrap-test-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            )))
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_file(&self.0);
        }
    }

    #[test]
    fn reads_one_input_file_and_rejects_read_protocol_or_argument_errors() {
        let file = Fixture::new();
        assert!(run([file.0.clone().into_os_string()].into_iter()).is_err());
        fs::write(&file.0, input(&[0.8; 3], 6)).unwrap();
        let output = run([file.0.clone().into_os_string()].into_iter()).unwrap();
        let parsed: serde_json::Value = serde_json::from_str(&output).unwrap();
        assert_eq!(parsed["result"]["decision"], "useful_improvement");
        assert_eq!(parsed["result"]["eligible_pairs"], 3);
        assert_eq!(parsed["result"]["median"], 0.8);
        assert!(run(std::iter::empty()).is_err());
        assert!(
            run([file.0.clone().into_os_string(), OsString::from("extra")].into_iter()).is_err()
        );
        fs::write(&file.0, b"bad").unwrap();
        assert!(run([file.0.clone().into_os_string()].into_iter()).is_err());
    }

    #[test]
    fn xorshift_matches_the_independent_php_sequence() {
        let mut state = 123;
        for expected in [
            31682556, 4018661298, 2101636938, 3842487452, 1628673942, 1875546790, 2388115785,
            3114636470,
        ] {
            assert_eq!(random(&mut state), expected);
        }
    }
    #[test]
    fn median_sorts_and_preserves_even_average_overflow() {
        assert_eq!(median(&mut [5.0, 1.0, 3.0]), 3.0);
        assert_eq!(median(&mut [4.0, 1.0, 3.0, 2.0]), 2.5);
        assert_eq!(median(&mut [f64::MAX; 4]), f64::INFINITY);
    }
    #[test]
    fn rejects_bad_protocol_lengths_and_nonfinite_ratios() {
        for length in [0, 31, 32, 40, 48, 55, 57] {
            assert!(process(&vec![0; length]).is_err());
        }
        for value in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY] {
            assert!(process(&input(&[value, 0.8, 0.8], 1)).is_err());
        }
    }
}
