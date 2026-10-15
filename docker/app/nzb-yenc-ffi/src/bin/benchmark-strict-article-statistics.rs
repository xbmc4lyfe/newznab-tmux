//! Bounded statistics for the explicit strict-article experiment, never adoption proof.

use serde::{
    Deserialize, Deserializer,
    de::{self, DeserializeSeed, IgnoredAny, MapAccess, SeqAccess, Visitor},
};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    collections::HashSet,
    fmt, fs,
    io::{self, Read, Write},
    path::{Path, PathBuf},
};

const MAX_INPUT_BYTES: usize = 67_108_864;
const MAX_STRATA: usize = 42;
const MAX_PAIRS: usize = 100_000;
const MAX_TOTAL_PAIRS: usize = 1_000_000;
const REPLICATES: usize = 10_000;
const THRESHOLD: f64 = 2.0 / 3.0;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Pair {
    pair: usize,
    cpp_ms: f64,
    rust_ms: f64,
}

#[derive(Clone, Copy, Deserialize)]
#[serde(rename_all = "lowercase")]
enum Family {
    Common,
    Uncommon,
}

struct Stratum {
    id: String,
    family: Family,
    invalid_pairs: usize,
    pairs: Vec<Pair>,
}
struct Input {
    protocol_sha256: String,
    seed: u32,
    strata: Vec<Stratum>,
}

// Never reserve from a JSON sequence size hint. Charge each decoded pair against
// both caps before pushing; nested arrays share one owned-vector budget.
struct PairsSeed<'a>(&'a mut usize);
impl<'de> DeserializeSeed<'de> for PairsSeed<'_> {
    type Value = Vec<Pair>;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> Result<Self::Value, D::Error> {
        struct PairsVisitor<'a>(&'a mut usize);
        impl<'de> Visitor<'de> for PairsVisitor<'_> {
            type Value = Vec<Pair>;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("bounded pairs array")
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut seq: A) -> Result<Self::Value, A::Error> {
                let mut pairs = Vec::new();
                loop {
                    if pairs.len() == MAX_PAIRS || *self.0 == MAX_TOTAL_PAIRS {
                        if seq.next_element::<IgnoredAny>()?.is_some() {
                            return Err(de::Error::custom("pair budget exceeded"));
                        }
                        break;
                    }
                    let Some(pair) = seq.next_element()? else {
                        break;
                    };
                    *self.0 = self
                        .0
                        .checked_add(1)
                        .ok_or_else(|| de::Error::custom("pair count overflow"))?;
                    pairs.push(pair);
                }
                Ok(pairs)
            }
        }
        d.deserialize_seq(PairsVisitor(self.0))
    }
}

struct StratumSeed<'a>(&'a mut usize);
impl<'de> DeserializeSeed<'de> for StratumSeed<'_> {
    type Value = Stratum;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> Result<Stratum, D::Error> {
        struct StratumVisitor<'a>(&'a mut usize);
        impl<'de> Visitor<'de> for StratumVisitor<'_> {
            type Value = Stratum;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("stratum object")
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<Stratum, A::Error> {
                let (mut id, mut family, mut invalid_pairs, mut pairs) = (None, None, None, None);
                while let Some(key) = map.next_key::<String>()? {
                    match key.as_str() {
                        "id" if id.is_none() => id = Some(map.next_value::<String>()?),
                        "family" if family.is_none() => family = Some(map.next_value()?),
                        "invalid_pairs" if invalid_pairs.is_none() => {
                            invalid_pairs = Some(map.next_value::<usize>()?)
                        }
                        "pairs" if pairs.is_none() => {
                            pairs = Some(map.next_value_seed(PairsSeed(self.0))?)
                        }
                        _ => return Err(de::Error::custom("unknown or duplicate stratum field")),
                    }
                }
                let id = id.ok_or_else(|| de::Error::missing_field("id"))?;
                if id.is_empty() || id.len() > 256 {
                    return Err(de::Error::custom("stratum id must be 1..256 bytes"));
                }
                let pairs = pairs.ok_or_else(|| de::Error::missing_field("pairs"))?;
                let invalid_pairs =
                    invalid_pairs.ok_or_else(|| de::Error::missing_field("invalid_pairs"))?;
                if pairs
                    .len()
                    .checked_add(invalid_pairs)
                    .is_none_or(|n| n > MAX_PAIRS)
                {
                    return Err(de::Error::custom("stratum observation budget exceeded"));
                }
                *self.0 = self
                    .0
                    .checked_add(invalid_pairs)
                    .filter(|n| *n <= MAX_TOTAL_PAIRS)
                    .ok_or_else(|| de::Error::custom("total observation budget exceeded"))?;
                Ok(Stratum {
                    id,
                    family: family.ok_or_else(|| de::Error::missing_field("family"))?,
                    invalid_pairs,
                    pairs,
                })
            }
        }
        d.deserialize_map(StratumVisitor(self.0))
    }
}

struct StrataSeed;
impl<'de> DeserializeSeed<'de> for StrataSeed {
    type Value = Vec<Stratum>;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> Result<Self::Value, D::Error> {
        struct StrataVisitor;
        impl<'de> Visitor<'de> for StrataVisitor {
            type Value = Vec<Stratum>;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("1..42 strata")
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut seq: A) -> Result<Self::Value, A::Error> {
                let mut strata = Vec::new();
                let mut total = 0usize;
                loop {
                    if strata.len() == MAX_STRATA {
                        if seq.next_element::<IgnoredAny>()?.is_some() {
                            return Err(de::Error::custom("stratum budget exceeded"));
                        }
                        break;
                    }
                    let Some(stratum) = seq.next_element_seed(StratumSeed(&mut total))? else {
                        break;
                    };
                    strata.push(stratum);
                }
                if strata.is_empty() {
                    return Err(de::Error::custom("at least one stratum is required"));
                }
                Ok(strata)
            }
        }
        d.deserialize_seq(StrataVisitor)
    }
}

impl<'de> Deserialize<'de> for Input {
    fn deserialize<D: Deserializer<'de>>(d: D) -> Result<Self, D::Error> {
        struct InputVisitor;
        impl<'de> Visitor<'de> for InputVisitor {
            type Value = Input;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("statistics protocol input")
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<Input, A::Error> {
                let (mut protocol, mut seed, mut strata) = (None, None, None);
                while let Some(key) = map.next_key::<String>()? {
                    match key.as_str() {
                        "protocol_sha256" if protocol.is_none() => {
                            protocol = Some(map.next_value::<String>()?)
                        }
                        "seed" if seed.is_none() => seed = Some(map.next_value()?),
                        "strata" if strata.is_none() => {
                            strata = Some(map.next_value_seed(StrataSeed)?)
                        }
                        _ => return Err(de::Error::custom("unknown or duplicate input field")),
                    }
                }
                let protocol_sha256 =
                    protocol.ok_or_else(|| de::Error::missing_field("protocol_sha256"))?;
                if protocol_sha256.len() != 64
                    || !protocol_sha256.bytes().all(|b| b.is_ascii_hexdigit())
                {
                    return Err(de::Error::custom(
                        "protocol_sha256 must contain exactly 64 hex digits",
                    ));
                }
                Ok(Input {
                    protocol_sha256,
                    seed: seed.ok_or_else(|| de::Error::missing_field("seed"))?,
                    strata: strata.ok_or_else(|| de::Error::missing_field("strata"))?,
                })
            }
        }
        d.deserialize_map(InputVisitor)
    }
}

fn percentile_index(count: usize) -> Option<usize> {
    count
        .checked_mul(99)?
        .checked_add(99)?
        .checked_div(100)?
        .checked_sub(1)
}

fn p99(values: &[f64]) -> Result<f64, String> {
    let index = percentile_index(values.len()).ok_or("p99 rank overflow or empty sample")?;
    let mut values = values.to_vec();
    Ok(*values.select_nth_unstable_by(index, f64::total_cmp).1)
}

fn median(values: &mut [f64]) -> f64 {
    let middle = values.len() / 2;
    let (_, upper, _) = values.select_nth_unstable_by(middle, f64::total_cmp);
    // Fetch the lower half independently for even lengths. No addition of two
    // huge ratios occurs.
    let upper = *upper;
    if values.len() % 2 == 1 {
        upper
    } else {
        let lower = values[..middle]
            .iter()
            .copied()
            .max_by(f64::total_cmp)
            .unwrap();
        lower + (upper - lower) / 2.0
    }
}

fn validate_times(cpp: &[f64], rust: &[f64]) -> Result<(), String> {
    if cpp.is_empty() || cpp.len() != rust.len() {
        return Err("timing arrays must be nonempty and paired".into());
    }
    for (&c, &r) in cpp.iter().zip(rust) {
        let ratio = r / c;
        let speedup = c / r;
        if !c.is_finite()
            || c <= 0.0
            || !r.is_finite()
            || r <= 0.0
            || !ratio.is_finite()
            || ratio <= 0.0
            || !speedup.is_finite()
            || speedup <= 0.0
        {
            return Err("timings and both ratio directions must be finite and positive".into());
        }
    }
    Ok(())
}

fn ratio(cpp: &[f64], rust: &[f64], uncommon: bool) -> Result<f64, String> {
    validate_times(cpp, rust)?;
    if uncommon {
        let c = p99(cpp)?;
        let r = p99(rust)?;
        let ratio = r / c;
        if !ratio.is_finite() || ratio <= 0.0 || !(1.0 / ratio).is_finite() {
            return Err("p99 ratio overflow or underflow".into());
        }
        Ok(ratio)
    } else {
        let mut ratios: Vec<_> = cpp.iter().zip(rust).map(|(c, r)| r / c).collect();
        Ok(median(&mut ratios))
    }
}

// SplitMix64, local to one stratum. Rejection before modulo avoids index bias.
struct Rng(u64);
impl Rng {
    fn index(&mut self, length: usize) -> usize {
        let bound = length as u64;
        let threshold = bound.wrapping_neg() % bound;
        loop {
            self.0 = self.0.wrapping_add(0x9e3779b97f4a7c15);
            let mut v = self.0;
            v = (v ^ (v >> 30)).wrapping_mul(0xbf58476d1ce4e5b9);
            v = (v ^ (v >> 27)).wrapping_mul(0x94d049bb133111eb);
            v ^= v >> 31;
            if v >= threshold {
                return (v % bound) as usize;
            }
        }
    }
}

fn bootstrap(
    cpp: &[f64],
    rust: &[f64],
    uncommon: bool,
    replicates: usize,
    seed: u32,
) -> Result<Vec<f64>, String> {
    validate_times(cpp, rust)?;
    if cpp.len() > MAX_PAIRS || replicates > REPLICATES {
        return Err("bootstrap work budget exceeded".into());
    }
    let mut rng = Rng(u64::from(seed));
    let mut c = vec![0.0; cpp.len()];
    let mut r = vec![0.0; cpp.len()];
    let ratios: Vec<_> = cpp.iter().zip(rust).map(|(c, r)| r / c).collect();
    let index = percentile_index(cpp.len()).ok_or("p99 rank overflow")?;
    let mut samples = Vec::new();
    for _ in 0..replicates {
        for i in 0..cpp.len() {
            let selected = rng.index(cpp.len());
            if uncommon {
                c[i] = cpp[selected];
                r[i] = rust[selected];
            } else {
                r[i] = ratios[selected];
            }
        }
        let estimate = if uncommon {
            let c99 = *c.select_nth_unstable_by(index, f64::total_cmp).1;
            let r99 = *r.select_nth_unstable_by(index, f64::total_cmp).1;
            r99 / c99
        } else {
            median(&mut r)
        };
        if !estimate.is_finite() || estimate <= 0.0 || !(1.0 / estimate).is_finite() {
            return Err("bootstrap ratio overflow or underflow".into());
        }
        samples.push(estimate);
    }
    Ok(samples)
}

fn analyze(bytes: &[u8]) -> Result<Value, String> {
    if bytes.len() > MAX_INPUT_BYTES {
        return Err("input exceeds 64 MiB".into());
    }
    let input: Input = serde_json::from_slice(bytes).map_err(|e| e.to_string())?;
    let mut ids = HashSet::new();
    // Validate every declared observation before doing any statistical work.
    for stratum in &input.strata {
        if !ids.insert(&stratum.id) {
            return Err(format!("duplicate stratum id: {}", stratum.id));
        }
        let mut pairs = HashSet::new();
        for pair in &stratum.pairs {
            if !pairs.insert(pair.pair) {
                return Err(format!(
                    "duplicate pair id in {}: {}",
                    stratum.id, pair.pair
                ));
            }
            validate_times(&[pair.cpp_ms], &[pair.rust_ms])
                .map_err(|e| format!("{}/{}, {e}", stratum.id, pair.pair))?;
        }
    }
    let declared = input.strata.len();
    // alpha/(2*m) = 1/(40*m). Floor/ceil endpoint ranks widen rather
    // than narrow the central percentile interval at finite B=10,000.
    let denominator = 40usize
        .checked_mul(declared)
        .ok_or("interval rank overflow")?;
    let lower_index = (REPLICATES - 1) / denominator;
    let upper_index = REPLICATES - 1 - lower_index;
    let mut results = Vec::new();
    let mut all = true;
    for stratum in input.strata {
        let uncommon = matches!(stratum.family, Family::Uncommon);
        let minimum = if uncommon { 2000 } else { 31 };
        let mut record = json!({
            "id": stratum.id,
            "family": if uncommon { "uncommon" } else { "common" },
            "pairs": stratum.pairs.len(),
            "invalid_pairs": stratum.invalid_pairs,
            "minimum_pairs": minimum,
            "method": if uncommon { "paired_index_bootstrap_of_separate_nearest_rank_p99_ratio" } else { "paired_bootstrap_median_of_rust_cpp_ratios" },
            "bootstrap_replicates": 0,
            "cpp_p99_ms": null,
            "rust_p99_ms": null,
            "rust_over_cpp": null,
            "observed_speedup": null,
            "interval": null,
            "status": "insufficient_pairs",
            "qualified": false
        });
        if stratum.invalid_pairs != 0 {
            record["status"] = json!("invalid_pairs");
        } else if stratum.pairs.len() >= minimum {
            let cpp: Vec<_> = stratum.pairs.iter().map(|p| p.cpp_ms).collect();
            let rust: Vec<_> = stratum.pairs.iter().map(|p| p.rust_ms).collect();
            let point = ratio(&cpp, &rust, uncommon)?;
            let mut samples = bootstrap(&cpp, &rust, uncommon, REPLICATES, input.seed)?;
            record["bootstrap_replicates"] = json!(samples.len());
            if uncommon {
                record["cpp_p99_ms"] = json!(p99(&cpp)?);
                record["rust_p99_ms"] = json!(p99(&rust)?);
            }
            let lower = *samples
                .select_nth_unstable_by(lower_index, f64::total_cmp)
                .1;
            let upper = *samples
                .select_nth_unstable_by(upper_index, f64::total_cmp)
                .1;
            let qualified = upper <= THRESHOLD;
            record["rust_over_cpp"] = json!(point);
            record["observed_speedup"] = json!(1.0 / point);
            record["interval"] = json!({"lower":lower,"upper":upper});
            record["qualified"] = json!(qualified);
            record["status"] = json!(if qualified {
                "qualified"
            } else if lower > THRESHOLD {
                "threshold_not_met"
            } else {
                "inconclusive"
            });
        }
        all &= record["qualified"] == true;
        results.push(record);
    }
    Ok(json!({
        "schema_version": 1,
        "protocol_sha256": input.protocol_sha256,
        "seed": input.seed,
        "rng": "local_splitmix64_rejection_indices",
        "declared_strata": declared,
        "bootstrap_replicates": REPLICATES,
        "simultaneous_confidence": 0.95,
        "confidence_interpretation": "nominal percentile bootstrap confidence; not a finite-sample coverage guarantee",
        "multiple_comparison_method": "Bonferroni across all declared strata including invalid/insufficient strata",
        "per_tail_probability": 0.025 / declared as f64,
        "interval_method": "percentile; floor/ceil of p*(B-1), zero-based",
        "interval_lower_index": lower_index,
        "interval_upper_index": upper_index,
        "p99_method": "nearest rank ceil(99*n/100), independently for CPP and Rust within paired-index resamples",
        "required_speedup": 1.5,
        "maximum_rust_over_cpp": THRESHOLD,
        "strata": results,
        "performance_thresholds_met": all,
        "adoption_qualified": false,
        "qualification_scope": "timing thresholds only; fresh-process independence, source/resource identity and complete 42-stratum coverage require the controller"
    }))
}

fn write_new_with(
    path: &Path,
    write: impl FnOnce(&mut fs::File) -> io::Result<()>,
) -> io::Result<()> {
    let mut options = fs::OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    let mut file = options.open(path)?;
    let identity = file.metadata()?;
    if let Err(error) = write(&mut file).and_then(|()| file.sync_all()) {
        // Never remove a path that has been replaced since our exclusive create.
        #[cfg(unix)]
        {
            use std::os::unix::fs::MetadataExt;
            if fs::symlink_metadata(path).is_ok_and(|now| {
                now.is_file() && now.dev() == identity.dev() && now.ino() == identity.ino()
            }) {
                let _ = fs::remove_file(path);
            }
        }
        #[cfg(not(unix))]
        {
            let _ = identity;
        }
        return Err(error);
    }
    Ok(())
}

fn paths(args: &[String]) -> Result<(PathBuf, PathBuf), String> {
    let (mut input, mut output) = (None, None);
    for arg in args {
        if let Some(path) = arg.strip_prefix("--input=").filter(|p| !p.is_empty()) {
            if input.replace(PathBuf::from(path)).is_some() {
                return Err("duplicate --input".into());
            }
        } else if let Some(path) = arg.strip_prefix("--output=").filter(|p| !p.is_empty()) {
            if output.replace(PathBuf::from(path)).is_some() {
                return Err("duplicate --output".into());
            }
        } else {
            return Err("use --input=PATH --output=PATH only".into());
        }
    }
    Ok((
        input.ok_or("missing --input")?,
        output.ok_or("missing --output")?,
    ))
}

fn run(args: &[String]) -> Result<bool, String> {
    let (input, output) = paths(args)?;
    let file = fs::File::open(&input).map_err(|e| format!("input: {e}"))?;
    if !file.metadata().map_err(|e| e.to_string())?.is_file() {
        return Err("input must be a regular file".into());
    }
    let mut bytes = Vec::new();
    file.take((MAX_INPUT_BYTES + 1) as u64)
        .read_to_end(&mut bytes)
        .map_err(|e| e.to_string())?;
    let (mut report, error) = match analyze(&bytes) {
        Ok(report) => (report, None),
        Err(error) => (
            json!({"schema_version":1,"status":"invalid_input","error":error,"performance_thresholds_met":false,"adoption_qualified":false}),
            Some(error),
        ),
    };
    report["input_sha256"] = json!(format!("{:x}", Sha256::digest(&bytes)));
    report["input_bytes"] = json!(bytes.len());
    report["input_complete"] = json!(bytes.len() <= MAX_INPUT_BYTES);
    report["input_sha256_scope"] = json!(if bytes.len() <= MAX_INPUT_BYTES {
        "complete_input"
    } else {
        "bounded_prefix_only"
    });
    let encoded = serde_json::to_vec_pretty(&report).map_err(|e| e.to_string())?;
    write_new_with(&output, |file| {
        file.write_all(&encoded)?;
        file.write_all(b"\n")
    })
    .map_err(|e| format!("output: {e}"))?;
    if let Some(error) = error {
        return Err(format!("invalid input (report retained): {error}"));
    }
    Ok(report["performance_thresholds_met"] == true)
}

fn main() {
    let args: Vec<_> = std::env::args().skip(1).collect();
    match run(&args) {
        Ok(true) => {}
        Ok(false) => {
            eprintln!("Performance thresholds unqualified; report retained.");
            std::process::exit(1);
        }
        Err(error) => {
            eprintln!("{error}");
            std::process::exit(2);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicU64, Ordering};
    static NEXT: AtomicU64 = AtomicU64::new(0);

    struct Scratch(std::path::PathBuf);
    impl Scratch {
        fn new() -> Self {
            let p = std::env::temp_dir().join(format!(
                "nntmux-strict-statistics-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&p).unwrap();
            Self(p)
        }
        fn path(&self, name: &str) -> std::path::PathBuf {
            self.0.join(name)
        }
    }
    impl Drop for Scratch {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.0).unwrap();
        }
    }
    fn input(ratio: f64, count: usize) -> Value {
        json!({"protocol_sha256": "a".repeat(64), "seed": 17, "strata": [{"id":"ordinary-lined", "family":"common", "invalid_pairs":0, "pairs": (0..count).map(|pair| json!({"pair":pair, "cpp_ms":2.0,"rust_ms":ratio*2.0})).collect::<Vec<_>>()}]})
    }
    fn report(value: &Value) -> Value {
        analyze(&serde_json::to_vec(value).unwrap()).unwrap()
    }

    #[test]
    fn literal_uncommon_p99_is_not_the_median_of_pair_ratios() {
        let cpp = vec![2.0; 2000];
        let mut rust = vec![1.0; 2000];
        rust[1979..].fill(100.0);
        assert_eq!(ratio(&cpp, &rust, false).unwrap(), 0.5);
        assert_eq!(ratio(&cpp, &rust, true).unwrap(), 50.0);
        assert_eq!(percentile_index(2000), Some(1979));
        assert_eq!(percentile_index(0), None);
        assert_eq!(percentile_index(usize::MAX), None);
    }

    #[test]
    fn paired_indices_preserve_dependence_and_local_rng_repeats() {
        let cpp: Vec<_> = (1..=100).map(f64::from).collect();
        let rust: Vec<_> = cpp.iter().map(|x| x * 2.0).collect();
        let first = bootstrap(&cpp, &rust, true, 300, 41).unwrap();
        assert_eq!(first, vec![2.0; 300]);
        assert_eq!(first, bootstrap(&cpp, &rust, true, 300, 41).unwrap());
        let varied: Vec<_> = cpp
            .iter()
            .enumerate()
            .map(|(i, x)| x * (i + 1) as f64 / 100.0)
            .collect();
        let first = bootstrap(&cpp, &varied, false, 80, 41).unwrap();
        assert_eq!(first, bootstrap(&cpp, &varied, false, 80, 41).unwrap());
        assert_ne!(first, bootstrap(&cpp, &varied, false, 80, 42).unwrap());
    }

    #[test]
    fn fixed_two_x_qualifies_threshold_only_and_one_x_fails() {
        let success = report(&input(0.5, 31));
        assert_eq!(success["performance_thresholds_met"], true);
        assert_eq!(success["adoption_qualified"], false);
        assert_eq!(success["bootstrap_replicates"], 10_000);
        assert_eq!(success["strata"][0]["status"], "qualified");
        assert_eq!(success["strata"][0]["rust_over_cpp"], 0.5);
        assert_eq!(success["strata"][0]["observed_speedup"], 2.0);
        assert_eq!(success["strata"][0]["interval"]["upper"], 0.5);
        let failure = report(&input(1.0, 31));
        assert_eq!(failure["performance_thresholds_met"], false);
        assert_eq!(failure["strata"][0]["status"], "threshold_not_met");
    }

    #[test]
    fn invalid_and_insufficient_pairs_withhold_survivor_ranking() {
        let mut value = input(0.1, 31);
        value["strata"][0]["invalid_pairs"] = json!(1);
        let invalid = report(&value);
        assert_eq!(invalid["strata"][0]["status"], "invalid_pairs");
        assert!(invalid["strata"][0]["rust_over_cpp"].is_null());
        assert!(invalid["strata"][0]["interval"].is_null());
        assert_eq!(invalid["performance_thresholds_met"], false);
        assert_eq!(
            report(&input(0.5, 30))["strata"][0]["status"],
            "insufficient_pairs"
        );
        let mut uncommon = input(0.5, 31);
        uncommon["strata"][0]["family"] = json!("uncommon");
        assert_eq!(report(&uncommon)["strata"][0]["minimum_pairs"], 2000);
        assert_eq!(
            report(&uncommon)["strata"][0]["status"],
            "insufficient_pairs"
        );
    }

    #[test]
    fn malformed_structure_duplicates_and_numeric_overflow_are_rejected() {
        let mut cases = Vec::new();
        let mut v = input(0.5, 1);
        v["protocol_sha256"] = json!("z".repeat(64));
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"][0]["family"] = json!("other");
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"][0]["pairs"][0]["cpp_ms"] = json!(0);
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"][0]["pairs"][0]["rust_ms"] = json!(-1);
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"][0]["pairs"][0]["cpp_ms"] = json!(f64::from_bits(1));
        v["strata"][0]["pairs"][0]["rust_ms"] = json!(f64::MAX);
        cases.push(v);
        let mut v = input(0.5, 2);
        v["strata"][0]["pairs"][1]["pair"] = json!(0);
        cases.push(v);
        let mut v = input(0.5, 1);
        let duplicate = v["strata"][0].clone();
        v["strata"].as_array_mut().unwrap().push(duplicate);
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"][0]["invalid_pairs"] = json!(usize::MAX);
        cases.push(v);
        let mut v = input(0.5, 1);
        v["strata"] = json!([]);
        cases.push(v);
        for value in cases {
            assert!(
                analyze(&serde_json::to_vec(&value).unwrap()).is_err(),
                "{value}"
            );
        }
        for raw in [b"{\"seed\":NaN}".as_slice(), b"{\"seed\":1e999}"] {
            assert!(analyze(raw).is_err());
        }
    }

    #[test]
    fn cli_exclusive_output_and_owned_write_failure_cleanup() {
        let scratch = Scratch::new();
        let source = scratch.path("input.json");
        let output = scratch.path("output.json");
        fs::write(&source, serde_json::to_vec(&input(0.5, 31)).unwrap()).unwrap();
        let args = vec![
            format!("--input={}", source.display()),
            format!("--output={}", output.display()),
        ];
        assert!(run(&args).unwrap());
        let saved = fs::read(&output).unwrap();
        assert_eq!(
            serde_json::from_slice::<Value>(&saved).unwrap()["adoption_qualified"],
            false
        );
        assert!(run(&args).is_err());
        assert_eq!(fs::read(&output).unwrap(), saved);
        let partial = scratch.path("partial");
        use std::io::Write;
        assert!(
            write_new_with(&partial, |file| {
                file.write_all(b"partial")?;
                Err(io::Error::other("fixture failure"))
            })
            .is_err()
        );
        assert!(!partial.exists());
        fs::write(&source, serde_json::to_vec(&input(1.0, 31)).unwrap()).unwrap();
        let failed = scratch.path("failed.json");
        assert!(!run(&[args[0].clone(), format!("--output={}", failed.display())]).unwrap());
        assert_eq!(
            serde_json::from_slice::<Value>(&fs::read(failed).unwrap()).unwrap()["performance_thresholds_met"],
            false
        );
    }

    #[test]
    fn bonferroni_uses_every_declared_stratum_and_keeps_all_failures() {
        let mut value = input(0.5, 31);
        let mut insufficient = value["strata"][0].clone();
        insufficient["id"] = json!("insufficient");
        insufficient["pairs"] = json!([]);
        let mut invalid = insufficient.clone();
        invalid["id"] = json!("invalid");
        invalid["invalid_pairs"] = json!(4);
        value["strata"]
            .as_array_mut()
            .unwrap()
            .extend([insufficient, invalid]);
        let result = report(&value);
        assert_eq!(result["declared_strata"], 3);
        assert_eq!(result["interval_lower_index"], 83);
        assert_eq!(result["interval_upper_index"], 9916);
        assert_eq!(result["strata"][0]["qualified"], true);
        assert_eq!(result["strata"][1]["status"], "insufficient_pairs");
        assert_eq!(result["strata"][2]["invalid_pairs"], 4);
        assert_eq!(result["strata"][2]["status"], "invalid_pairs");
        assert_eq!(result["performance_thresholds_met"], false);
        let mut varied = input(0.5, 31);
        for pair in 15..31 {
            varied["strata"][0]["pairs"][pair]["rust_ms"] = json!(2.0);
        }
        let result = report(&varied);
        assert_eq!(result["strata"][0]["status"], "inconclusive");
        assert_eq!(result["performance_thresholds_met"], false);
    }

    #[test]
    fn uncommon_report_exposes_separate_p99_values_and_executed_replicates() {
        let mut value = input(0.5, 2000);
        value["strata"][0]["family"] = json!("uncommon");
        let result = report(&value);
        assert_eq!(result["strata"][0]["cpp_p99_ms"], 2.0);
        assert_eq!(result["strata"][0]["rust_p99_ms"], 1.0);
        assert_eq!(result["strata"][0]["bootstrap_replicates"], REPLICATES);
        assert_eq!(result["performance_thresholds_met"], true);
        value["strata"][0]["invalid_pairs"] = json!(1);
        let invalid = report(&value);
        assert_eq!(invalid["strata"][0]["bootstrap_replicates"], 0);
        assert!(invalid["strata"][0]["cpp_p99_ms"].is_null());
        assert!(invalid["strata"][0]["rust_p99_ms"].is_null());
    }

    #[test]
    fn bounded_deserialization_charges_pairs_and_invalid_observations() {
        let pair = r#"{"pair":0,"cpp_ms":2,"rust_ms":1}"#;
        let raw = format!(
            "[{}]",
            std::iter::repeat_n(pair, MAX_PAIRS + 1)
                .collect::<Vec<_>>()
                .join(",")
        );
        let mut count = 0usize;
        let mut deserializer = serde_json::Deserializer::from_str(&raw);
        assert!(
            PairsSeed(&mut count)
                .deserialize(&mut deserializer)
                .is_err()
        );
        assert_eq!(count, MAX_PAIRS);
        let raw = format!("[{pair},{pair}]");
        let mut count = MAX_TOTAL_PAIRS - 1;
        let mut deserializer = serde_json::Deserializer::from_str(&raw);
        assert!(
            PairsSeed(&mut count)
                .deserialize(&mut deserializer)
                .is_err()
        );
        assert_eq!(count, MAX_TOTAL_PAIRS);
        let mut value = input(0.5, 0);
        value["strata"] = json!(
            (0..43)
                .map(
                    |id| json!({"id":id.to_string(),"family":"common","invalid_pairs":0,"pairs":[]})
                )
                .collect::<Vec<_>>()
        );
        assert!(analyze(&serde_json::to_vec(&value).unwrap()).is_err());
        let mut value = input(0.5, 1);
        value["strata"][0]["invalid_pairs"] = json!(MAX_PAIRS);
        assert!(analyze(&serde_json::to_vec(&value).unwrap()).is_err());
        assert!(analyze(&vec![b' '; MAX_INPUT_BYTES + 1]).is_err());
        let raw = format!(
            r#"{{"seed":1,"seed":2,"protocol_sha256":"{}","strata":[]}}"#,
            "a".repeat(64)
        );
        assert!(analyze(raw.as_bytes()).is_err());
    }

    #[test]
    fn cli_retains_invalid_report_and_rejects_bad_or_duplicate_options() {
        let scratch = Scratch::new();
        let input = scratch.path("bad.json");
        let output = scratch.path("failure.json");
        fs::write(&input, b"{\"seed\":NaN}").unwrap();
        assert!(
            run(&[
                format!("--input={}", input.display()),
                format!("--output={}", output.display())
            ])
            .is_err()
        );
        let failure: Value = serde_json::from_slice(&fs::read(output).unwrap()).unwrap();
        assert_eq!(failure["status"], "invalid_input");
        assert_eq!(failure["adoption_qualified"], false);
        assert_eq!(failure["performance_thresholds_met"], false);
        for args in [
            vec![],
            vec!["--input=x".into()],
            vec!["--input=x".into(), "--input=y".into(), "--output=z".into()],
            vec!["--input=x".into(), "--output=z".into(), "--output=y".into()],
            vec!["--input=x".into(), "--output=z".into(), "--seed=1".into()],
        ] {
            assert!(paths(&args).is_err());
        }
    }

    #[cfg(unix)]
    #[test]
    fn exclusive_output_rejects_symlinks_and_cleanup_preserves_replacement() {
        let scratch = Scratch::new();
        let target = scratch.path("target");
        let link = scratch.path("link");
        fs::write(&target, b"retain").unwrap();
        std::os::unix::fs::symlink(&target, &link).unwrap();
        assert!(write_new_with(&link, |_| Ok(())).is_err());
        assert_eq!(fs::read(&target).unwrap(), b"retain");
        let output = scratch.path("replaced");
        assert!(
            write_new_with(&output, |_| {
                fs::remove_file(&output)?;
                fs::write(&output, b"new owner")?;
                Err(io::Error::other("replacement fixture"))
            })
            .is_err()
        );
        assert_eq!(fs::read(&output).unwrap(), b"new owner");
    }
}
