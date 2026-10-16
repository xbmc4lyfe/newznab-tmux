//! Offline verification of complete paired qualification bundles.
//!
//! External files are retained as `objects/<sha256>`; paths recorded inside the
//! original output directory are resolved relative to the supplied bundle. No
//! recorded absolute path is ever opened. This command executes no benchmark.
use crate::{benchmark, tooling};
use serde_json::{Value, json};
use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Component, Path, PathBuf};

const PROTOCOL: &str = "rust-paired-yenc-v4";
const INCUMBENT_COMMIT: &str = "0ecc3701f0d95e85ea167bb07e105b4a9ed1d836";
const INCUMBENT_SHA256: &str = "3620299ff049fdb53ed2a3a6c4b17c59d09aa23eaa933ef52310ac3078fefa3a";
const CRATE: &str = "docker/app/nzb-yenc-ffi";
const RUST_COMPILER: &str = "rustc 1.98.1 (48a229cea 2026-09-01)";
const CPP_COMPILER: &str = "Debian GCC 12.2.0-14+deb12u1";
const BUILDER_IMAGE: &str =
    "sha256:93ce27a88655056a51dbdd8f5f2d7ddc071c7b0070fb288a37b5a285fc83971e";
const PHP_IMAGE: &str = "sha256:7b728177944ca26dc6688d8d57015ba00251f1864f2fe2d0e620f37005027263";

fn require(condition: bool, message: &str) -> Result<(), String> {
    if condition {
        Ok(())
    } else {
        Err(message.into())
    }
}
fn string<'a>(value: &'a Value, key: &str) -> Result<&'a str, String> {
    value[key]
        .as_str()
        .ok_or_else(|| format!("missing string {key}"))
}
fn array<'a>(value: &'a Value, key: &str) -> Result<&'a Vec<Value>, String> {
    value[key]
        .as_array()
        .ok_or_else(|| format!("missing array {key}"))
}
fn positive(value: &Value, key: &str) -> Result<f64, String> {
    value[key]
        .as_f64()
        .filter(|n| n.is_finite() && *n > 0.)
        .ok_or_else(|| format!("invalid positive {key}"))
}
fn integer(value: &Value, key: &str) -> Result<usize, String> {
    value[key]
        .as_u64()
        .and_then(|n| usize::try_from(n).ok())
        .ok_or_else(|| format!("invalid integer {key}"))
}
fn read(path: &Path) -> Result<Value, String> {
    parse(&fs::read(path).map_err(|e| format!("{}: {e}", path.display()))?)
        .map_err(|e| format!("{}: {e}", path.display()))
}
fn parse(bytes: &[u8]) -> Result<Value, String> {
    serde_json::from_slice::<StrictJson>(bytes)
        .map(|value| value.0)
        .map_err(|e| e.to_string())
}
// Value's default deserializer keeps the last duplicate object key. Retained
// evidence inventories must be unambiguous before cardinality checks.
struct StrictJson(Value);
impl<'de> serde::Deserialize<'de> for StrictJson {
    fn deserialize<D: serde::Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct Visitor;
        impl<'de> serde::de::Visitor<'de> for Visitor {
            type Value = StrictJson;
            fn expecting(&self, formatter: &mut std::fmt::Formatter) -> std::fmt::Result {
                formatter.write_str("JSON without duplicate object keys")
            }
            fn visit_bool<E: serde::de::Error>(self, value: bool) -> Result<StrictJson, E> {
                Ok(StrictJson(json!(value)))
            }
            fn visit_i64<E: serde::de::Error>(self, value: i64) -> Result<StrictJson, E> {
                Ok(StrictJson(json!(value)))
            }
            fn visit_u64<E: serde::de::Error>(self, value: u64) -> Result<StrictJson, E> {
                Ok(StrictJson(json!(value)))
            }
            fn visit_f64<E: serde::de::Error>(self, value: f64) -> Result<StrictJson, E> {
                serde_json::Number::from_f64(value)
                    .map(|number| StrictJson(Value::Number(number)))
                    .ok_or_else(|| E::custom("nonfinite JSON number"))
            }
            fn visit_str<E: serde::de::Error>(self, value: &str) -> Result<StrictJson, E> {
                Ok(StrictJson(Value::String(value.into())))
            }
            fn visit_string<E: serde::de::Error>(self, value: String) -> Result<StrictJson, E> {
                Ok(StrictJson(Value::String(value)))
            }
            fn visit_unit<E: serde::de::Error>(self) -> Result<StrictJson, E> {
                Ok(StrictJson(Value::Null))
            }
            fn visit_seq<A: serde::de::SeqAccess<'de>>(
                self,
                mut seq: A,
            ) -> Result<StrictJson, A::Error> {
                let mut values = Vec::new();
                while let Some(value) = seq.next_element::<StrictJson>()? {
                    values.push(value.0);
                }
                Ok(StrictJson(Value::Array(values)))
            }
            fn visit_map<A: serde::de::MapAccess<'de>>(
                self,
                mut map: A,
            ) -> Result<StrictJson, A::Error> {
                let mut values = serde_json::Map::new();
                while let Some((key, value)) = map.next_entry::<String, StrictJson>()? {
                    if values.insert(key.clone(), value.0).is_some() {
                        return Err(serde::de::Error::custom(format!(
                            "duplicate JSON key {key}"
                        )));
                    }
                }
                Ok(StrictJson(Value::Object(values)))
            }
        }
        deserializer.deserialize_any(Visitor)
    }
}
fn digest(value: &str) -> Result<(), String> {
    require(
        value.len() == 64
            && value
                .bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)),
        "invalid SHA256",
    )
}
fn safe_relative(path: &Path) -> Result<(), String> {
    require(
        !path.as_os_str().is_empty()
            && path.components().all(|c| matches!(c, Component::Normal(_))),
        "unsafe relative bundle/source path",
    )
}
fn owned_file(root: &Path, relative: &Path) -> Result<PathBuf, String> {
    safe_relative(relative)?;
    let mut cursor = root.to_path_buf();
    for part in relative.components() {
        cursor.push(part);
        require(
            !fs::symlink_metadata(&cursor)
                .map_err(|e| format!("{}: {e}", cursor.display()))?
                .file_type()
                .is_symlink(),
            "symlink in retained evidence",
        )?;
    }
    require(cursor.is_file(), "retained evidence must be regular file")?;
    Ok(cursor)
}
struct Bundle {
    root: PathBuf,
    original: PathBuf,
    next: usize,
    child_names: BTreeSet<String>,
}
impl Bundle {
    fn resolve(&self, original: &str, hash: &str) -> Result<PathBuf, String> {
        digest(hash)?;
        let recorded = Path::new(original);
        let relative = if let Ok(relative) = recorded.strip_prefix(&self.original) {
            relative.to_path_buf()
        } else {
            Path::new("objects").join(hash)
        };
        let path = owned_file(&self.root, &relative)?;
        require(
            tooling::file_sha256(&path)? == hash,
            "retained file hash mismatch",
        )?;
        Ok(path)
    }
    fn artifact(&self, value: &Value) -> Result<(), String> {
        self.resolve(string(value, "path")?, string(value, "sha256")?)?;
        Ok(())
    }
    fn child(&mut self, suffix: &str, executor: &Value) -> Result<(Value, Value), String> {
        let name = format!("{:06}-{suffix}", self.next);
        self.next += 1;
        require(
            self.child_names.remove(&name),
            &format!("missing, reused or reordered child {name}"),
        )?;
        let relative = Path::new("children").join(&name);
        let stdout = fs::read(owned_file(&self.root, &relative.join("stdout"))?)
            .map_err(|e| e.to_string())?;
        let stderr = fs::read(owned_file(&self.root, &relative.join("stderr"))?)
            .map_err(|e| e.to_string())?;
        let command = read(&owned_file(&self.root, &relative.join("command.json"))?)?;
        let outcome = read(&owned_file(&self.root, &relative.join("outcome.json"))?)?;
        let report: Value =
            parse(&stdout).map_err(|e| format!("invalid raw child JSON {name}: {e}"))?;
        require(
            stderr.is_empty()
                && outcome["stderr_bytes"] == 0
                && outcome["status"] == "reported"
                && outcome["exit_code"] == 0
                && outcome["signal"] == 0
                && outcome["wait_status"] == 0
                && outcome["timeout"] == false
                && outcome["parse_error"].is_null(),
            "failing raw child outcome",
        )?;
        require(
            outcome["report"] == report && report["status"] == "ok",
            "altered raw child report",
        )?;
        require(
            command["rss_method"] == "wait4"
                && command["os"] == "linux"
                && command["timeout_ms"] == 120000
                && outcome["rss_units"] == "KiB",
            "invalid child timing/RSS method",
        )?;
        let native = integer(&outcome, "peak_rss_native")?;
        require(
            native > 0 && native.checked_mul(1024) == Some(integer(&outcome, "peak_rss_bytes")?),
            "invalid child RSS conversion",
        )?;
        let lifetime = positive(&outcome, "lifetime_ms")?;
        require(lifetime < 120000., "invalid child lifetime")?;
        for receipt in [&command, &outcome] {
            require(
                receipt["machine_conditions"]["arch"] == "aarch64"
                    && receipt["machine_conditions"]["os"] == "linux"
                    && receipt["machine_conditions"]["unix_time_ms"]
                        .as_u64()
                        .is_some(),
                "missing child machine conditions",
            )?;
        }
        require(
            outcome["machine_conditions"]["unix_time_ms"].as_u64()
                >= command["machine_conditions"]["unix_time_ms"].as_u64(),
            "reversed child timestamps",
        )?;
        self.collector(&relative, &command, &outcome, executor)?;
        let child = json!({"lifetime_ms":lifetime,"peak_rss_bytes":outcome["peak_rss_bytes"],"report":report});
        Ok((child, command))
    }

    fn collector(
        &self,
        relative: &Path,
        worker_command: &Value,
        worker_outcome: &Value,
        executor: &Value,
    ) -> Result<(), String> {
        let request = read(&owned_file(
            &self.root,
            &relative.join("capture-request.json"),
        )?)?;
        require(
            request
                == json!({"program":worker_command["program"],"arguments":worker_command["arguments"],"cwd":null,"environment":{},"timeout_ms":120000}),
            "altered collector capture request",
        )?;
        let pid = |file: &Path| -> Result<u64, String> {
            let bytes =
                fs::read_to_string(owned_file(&self.root, file)?).map_err(|e| e.to_string())?;
            bytes
                .parse::<u64>()
                .ok()
                .filter(|pid| *pid > 0 && *pid <= i32::MAX as u64)
                .ok_or("invalid retained process PID".into())
        };
        let worker_pid = pid(&relative.join("worker.pid"))?;
        let collector_pid = pid(&relative.join("collector/worker.pid"))?;
        require(worker_pid != collector_pid, "collector reuses worker PID")?;
        let command = read(&owned_file(
            &self.root,
            &relative.join("collector/command.json"),
        )?)?;
        let outcome_path = owned_file(&self.root, &relative.join("collector/outcome.json"))?;
        let outcome = read(&outcome_path)?;
        let stdout = fs::read(owned_file(&self.root, &relative.join("collector/stdout"))?)
            .map_err(|e| e.to_string())?;
        let stderr = fs::read(owned_file(&self.root, &relative.join("collector/stderr"))?)
            .map_err(|e| e.to_string())?;
        let report: Value = parse(&stdout)?;
        let original = self.original.join(relative);
        require(
            command["program"] == executor["path"]
                && command["arguments"]
                    == json!([
                        "capture-child",
                        "--request",
                        original.join("capture-request.json"),
                        "--directory",
                        original
                    ])
                && command["timeout_ms"] == 125000
                && command["rss_method"] == "wait4"
                && command["os"] == "linux",
            "altered collector command/protocol",
        )?;
        require(
            stderr.is_empty()
                && outcome["stderr_bytes"] == 0
                && outcome["status"] == "reported"
                && outcome["exit_code"] == 0
                && outcome["signal"] == 0
                && outcome["wait_status"] == 0
                && outcome["timeout"] == false
                && outcome["parse_error"].is_null()
                && outcome["report"] == report
                && report["status"] == "ok",
            "failed or altered collector raw receipt",
        )?;
        let peak = integer(&outcome, "peak_rss_native")?;
        require(
            peak > 0
                && peak.checked_mul(1024) == Some(integer(&outcome, "peak_rss_bytes")?)
                && outcome["rss_units"] == "KiB",
            "invalid diagnostic collector RSS",
        )?;
        require(
            positive(&outcome, "lifetime_ms")? >= positive(worker_outcome, "lifetime_ms")?
                && positive(&outcome, "lifetime_ms")? < 125000.,
            "invalid collector/worker lifetimes",
        )?;
        for record in [&command, &outcome] {
            require(
                record["machine_conditions"]["arch"] == "aarch64"
                    && record["machine_conditions"]["os"] == "linux"
                    && record["machine_conditions"]["unix_time_ms"]
                        .as_u64()
                        .is_some(),
                "missing collector machine conditions",
            )?;
        }
        require(
            outcome["machine_conditions"]["unix_time_ms"].as_u64()
                >= command["machine_conditions"]["unix_time_ms"].as_u64(),
            "reversed collector timestamps",
        )?;
        for (key, file) in [
            ("worker_outcome_sha256", "outcome.json"),
            ("worker_stdout_sha256", "stdout"),
            ("worker_stderr_sha256", "stderr"),
        ] {
            require(
                report[key]
                    == tooling::file_sha256(&owned_file(&self.root, &relative.join(file))?)?,
                "collector worker hash binding mismatch",
            )?;
        }
        require(
            report["worker_lifetime_ms"] == worker_outcome["lifetime_ms"]
                && report["worker_peak_rss_bytes"] == worker_outcome["peak_rss_bytes"]
                && report["worker_pid"] == worker_pid,
            "collector uses incorrect worker metrics/PID",
        )?;
        let parent = read(&owned_file(&self.root, &relative.join("collector.json"))?)?;
        require(
            parent
                == json!({"rss_role":"diagnostic_inherited_parent_floor","worker_pid":worker_pid,"collector_outcome":outcome,"error":null}),
            "altered diagnostic collector receipt",
        )?;
        Ok(())
    }
}
fn source_inventory(root: &Path) -> Result<BTreeSet<String>, String> {
    fn walk(root: &Path, path: &Path, set: &mut BTreeSet<String>) -> Result<(), String> {
        for entry in fs::read_dir(path).map_err(|e| e.to_string())? {
            let entry = entry.map_err(|e| e.to_string())?;
            let kind = entry.file_type().map_err(|e| e.to_string())?;
            require(!kind.is_symlink(), "symlink in measured Rust sources")?;
            if kind.is_dir() {
                walk(root, &entry.path(), set)?;
            } else if entry
                .path()
                .extension()
                .is_some_and(|extension| extension == "rs")
            {
                set.insert(
                    entry
                        .path()
                        .strip_prefix(root)
                        .map_err(|e| e.to_string())?
                        .to_string_lossy()
                        .into_owned(),
                );
            }
        }
        Ok(())
    }
    let mut inventory = BTreeSet::new();
    walk(root, &root.join(CRATE).join("src"), &mut inventory)?;
    for path in [
        format!("{CRATE}/Cargo.toml"),
        format!("{CRATE}/Cargo.lock"),
        "scripts/benchmark-yenc.php".into(),
        "app/Services/Yenc/NativePayloadDecoder.php".into(),
        "app/Services/YencService.php".into(),
        "app/Services/NNTP/NNTPService.php".into(),
        "tests/Fixtures/YencArticles.php".into(),
        "tests/Support/YencNntpHarness.php".into(),
        "composer.lock".into(),
        "vendor/composer/installed.json".into(),
    ] {
        inventory.insert(path);
    }
    inventory.remove(&format!("{CRATE}/src/evidence.rs"));
    inventory.remove(&format!("{CRATE}/src/bin/verify-yenc-evidence.rs"));
    Ok(inventory)
}
fn runtime_sources(runtime: &Value, source: &Path) -> Result<(), String> {
    require(
        runtime["sources"]["incumbent_fork_master"] == INCUMBENT_COMMIT,
        "altered frozen incumbent source",
    )?;
    let sources = runtime["sources"]["sha256"]
        .as_object()
        .ok_or("missing exact measured source inventory")?;
    let inventory = source_inventory(source)?;
    require(
        sources.keys().cloned().collect::<BTreeSet<_>>() == inventory,
        "missing or extra measured source inventory",
    )?;
    for (path, hash) in sources {
        let hash = hash.as_str().ok_or("invalid source hash")?;
        digest(hash)?;
        require(
            tooling::file_sha256(&owned_file(source, Path::new(path))?)? == hash,
            &format!("measured source differs: {path}"),
        )?;
    }
    let cargo =
        fs::read_to_string(source.join(CRATE).join("Cargo.toml")).map_err(|e| e.to_string())?;
    let mut in_profile = false;
    let mut sections = 0;
    let mut profile = BTreeMap::new();
    for line in cargo.lines() {
        let line = line.split('#').next().unwrap_or("").trim();
        if line.starts_with('[') {
            in_profile = line == "[profile.release]";
            if in_profile {
                sections += 1;
            }
        } else if in_profile && !line.is_empty() {
            let (key, value) = line
                .split_once('=')
                .ok_or("invalid measured release profile")?;
            require(
                profile.insert(key.trim(), value.trim()).is_none(),
                "duplicate measured profile field",
            )?;
        }
    }
    require(
        sections == 1
            && profile.get("opt-level") == Some(&"3")
            && profile.get("lto") == Some(&"true")
            && profile.get("codegen-units") == Some(&"1"),
        "measured Cargo source/profile mismatch",
    )?;
    require(
        runtime["lockfile"]["cargo_sha256"] == sources[&format!("{CRATE}/Cargo.lock")]
            && runtime["lockfile"]["composer_sha256"] == sources["composer.lock"]
            && runtime["vendor"]["installed_json_sha256"]
                == sources["vendor/composer/installed.json"],
        "lockfile/vendor identity mismatch",
    )?;
    require(
        runtime["compilers"]["rust"] == RUST_COMPILER
            && runtime["compilers"]["cpp"] == CPP_COMPILER
            && runtime["image_ids"]["builder"] == BUILDER_IMAGE
            && runtime["image_ids"]["php"] == PHP_IMAGE,
        "frozen compiler/image identity mismatch",
    )?;
    require(
        runtime["build_flags"]["profile"] == json!({"opt_level":3,"lto":true,"codegen_units":1})
            && runtime["build_flags"]["build_native"] == false,
        "invalid frozen build flags/profile",
    )?;
    for role in ["candidate", "incumbent"] {
        require(
            runtime["build_flags"][role]
                == json!([
                    "cargo test --release --locked --offline",
                    "cargo build --release --locked --offline"
                ]),
            "invalid measured Cargo build flags",
        )?;
    }
    for key in ["php", "extensions", "cpu", "machine_conditions"] {
        require(
            runtime.get(key).is_some_and(|v| !v.is_null()),
            "missing runtime provenance",
        )?;
    }
    require(
        runtime["protocol"] == PROTOCOL
            && runtime["cpu"]["linux_arch"] == "aarch64"
            && runtime["php"]["sapi"] == "cli"
            && runtime["php"]["ffi"] == "1"
            && runtime["php"]["opcache_cli"] == "0"
            && runtime["php"]["jit"] == "disable",
        "wrong timed PHP/architecture configuration",
    )?;
    Ok(())
}
fn shuffle<T>(values: &mut [T], state: &mut u32) {
    for i in (1..values.len()).rev() {
        *state ^= *state << 13;
        *state ^= *state >> 17;
        *state ^= *state << 5;
        values.swap(i, *state as usize % (i + 1));
    }
}
fn strict_gate(pairs: &[Value], improvement: Option<f64>, php: bool) -> Result<(), String> {
    require(
        benchmark::decision(pairs, improvement)?,
        "recomputed performance gate failed",
    )?;
    if php {
        for pair in pairs {
            require(
                positive(pair, "lifecycle_median_ratio")? <= 1.05
                    && positive(pair, "lifecycle_p95_ratio")? <= 1.05,
                "recomputed PHP lifecycle gate failed",
            )?;
        }
    }
    Ok(())
}
fn expected_arguments(
    identity: &Value,
    artifact: &Value,
    work: &Value,
    comparison: &Value,
    iterations: usize,
    freeze: bool,
) -> Result<Value, String> {
    let php = work["php"] == true;
    let mut args: Vec<String> = if php {
        vec![
            string(&identity["php_worker"], "path")?.into(),
            "--worker=native".into(),
            "--samples=3".into(),
            format!("--native={}", string(artifact, "path")?),
            format!("--path={}", string(work, "operation")?),
            format!("--data-path={}", string(work, "data")?),
            format!("--article-path={}", string(work, "article")?),
        ]
    } else {
        vec![
            "bench-worker".into(),
            "--library".into(),
            string(artifact, "path")?.into(),
            "--library-sha256".into(),
            string(artifact, "sha256")?.into(),
            "--operation".into(),
            string(work, "operation")?.into(),
            "--data".into(),
            string(work, "data")?.into(),
            "--input".into(),
            string(work, "input")?.into(),
            "--iterations".into(),
            iterations.to_string(),
            "--data-sha256".into(),
            string(work, "data_sha256")?.into(),
            "--input-sha256".into(),
            string(work, "input_sha256")?.into(),
        ]
    };
    if !php && !freeze {
        args.extend([
            "--expected".into(),
            string(comparison, "expected_receipt")?.into(),
            "--expected-sha256".into(),
            string(comparison, "expected_receipt_sha256")?.into(),
        ]);
    }
    if !php && artifact["name"] == "cpp" {
        args.extend(["--baseline-role".into(), "cpp".into()]);
    }
    Ok(json!(args))
}
// Each argument is an independent frozen evidence binding or verification mode.
// Keep them explicit when auditing the child command and receipt checks together.
#[allow(clippy::too_many_arguments)]
fn validated_child(
    bundle: &mut Bundle,
    identity: &Value,
    comparison: &Value,
    artifact: &Value,
    phase: &str,
    iterations: usize,
    freeze: bool,
    measured: bool,
) -> Result<Value, String> {
    let work = &comparison["workload"];
    let suffix = format!(
        "{phase}-{}-{}",
        string(artifact, "name")?,
        string(work, "id")?
    );
    let (child, command) = bundle.child(&suffix, &identity["executor"])?;
    let report = &child["report"];
    let php = work["php"] == true;
    require(
        command["program"] == identity[if php { "php" } else { "executor" }]["path"]
            && command["arguments"]
                == expected_arguments(identity, artifact, work, comparison, iterations, freeze)?,
        "child command differs from frozen protocol",
    )?;
    require(
        report["library_sha256"] == artifact["sha256"],
        "child artifact identity mismatch",
    )?;
    if php {
        let times = benchmark::validate_php(
            report,
            string(artifact, "sha256")?,
            string(work, "data_sha256")?,
            integer(work, "size")?,
        )?;
        require(
            times.len() == 3 && report.get("observations").is_some(),
            "missing ordered PHP calls",
        )?;
        require(
            times.iter().sum::<f64>() <= positive(&child, "lifetime_ms")?,
            "PHP timings exceed child lifetime",
        )?;
        let rss = positive(&child, "peak_rss_bytes")?;
        require(
            (positive(report, "peak_rss_bytes")? - rss).abs() / rss <= 0.10,
            "PHP RSS differs from wait4",
        )?;
    } else {
        require(
            report["operation"] == work["operation"]
                && report["iterations"] == iterations
                && report["batch_results"]["count"] == iterations
                && report["input_sha256"] == work["input_sha256"]
                && report["data_sha256"] == work["data_sha256"],
            "invalid native child identity/correctness count",
        )?;
        let expected_version = match string(artifact, "name")? {
            "cpp" => 0x010101,
            "candidate" | "incumbent" => 0x0103,
            _ => return Err("unknown native artifact role".into()),
        };
        require(
            report["os"] == "linux"
                && report["arch"] == "aarch64"
                && report["version"] == expected_version,
            "native child runtime/version mismatch",
        )?;
        let elapsed = positive(report, "elapsed_ms")?;
        require(
            elapsed <= positive(&child, "lifetime_ms")? && (!measured || elapsed >= 250.),
            "native timings below floor or above lifetime",
        )?;
        let operation = string(work, "operation")?;
        let slots = benchmark::chunk_slots(
            integer(work, "input_bytes")?,
            benchmark::allocated_metadata(operation),
            benchmark::operation_needs_bytes(operation),
        )?;
        require(
            report["chunk_slots"] == slots
                && report["timed_chunks"] == iterations.div_ceil(slots)
                && report["chunk_memory_limit"] == 33554432
                && report["maximum_chunk_slots"] == 8
                && report["scalar_chunk_slots"] == 4096,
            "invalid native bounded witness protocol",
        )?;
        if artifact["name"] == "cpp" {
            require(
                report["decoder_kernel"] == 4096 && report["crc_kernel"] == 72,
                "reference was not actually accelerated",
            )?;
        }
        require(report["receipt"].is_object(), "missing native receipt")?;
        let receipt = &report["receipt"];
        if operation == tooling::FINGERPRINT_OPERATION {
            require(
                *receipt == benchmark::expected_fingerprint_receipt(),
                "altered independent fingerprint transcript",
            )?;
        }
        let number = if receipt["diagnostic"] == true {
            integer(report, "diagnostic_value")? as u64
        } else {
            integer(receipt, "number")? as u64
        };
        let witness = (integer(receipt, "count")? as u64)
            .wrapping_add((integer(receipt, "crc")? as u64) << 32)
            .wrapping_add(number);
        require(
            report["batch_results"]["value"].as_u64() == Some(witness)
                && report["batch_results"]["encoding"]
                    == "one run; every scalar or byte/state witness checked",
            "invalid intermediate witness receipt",
        )?;
        if !freeze {
            let expected = read(&bundle.resolve(
                string(comparison, "expected_receipt")?,
                string(comparison, "expected_receipt_sha256")?,
            )?)?;
            require(
                report["receipt"] == expected
                    && report["expected_sha256"] == comparison["expected_receipt_sha256"],
                "native output disagrees with frozen expected receipt",
            )?;
        } else {
            require(
                report["expected_sha256"].is_null(),
                "receipt freeze used an expectation",
            )?;
        }
    }
    Ok(child)
}
fn summary(children: &[Value], work: &Value, artifact: &Value) -> Result<Value, String> {
    require(children.len() == 11, "eleven measured children required")?;
    let php = work["php"] == true;
    let mut times = Vec::new();
    let mut lifetimes = Vec::new();
    let mut rss = 0usize;
    for child in children {
        if php {
            times.extend(benchmark::validate_php(
                &child["report"],
                string(artifact, "sha256")?,
                string(work, "data_sha256")?,
                integer(work, "size")?,
            )?);
        } else {
            times.push(positive(&child["report"], "elapsed_ms")?);
        }
        lifetimes.push(positive(child, "lifetime_ms")?);
        rss = rss.max(integer(child, "peak_rss_bytes")?);
    }
    let timed = tooling::summarize(&times)?;
    let life = tooling::summarize(&lifetimes)?;
    Ok(
        json!({"median_ms":timed.median,"p95_ms":timed.p95,"peak_rss_bytes":rss,"lifecycle_median_ms":life.median,"lifecycle_p95_ms":life.p95,"timed_observations_ms":times,"lifecycle_observations_ms":lifetimes,"children":children,"nested_calls":php,"ordered":true}),
    )
}

#[derive(PartialEq, Eq)]
struct CandidateStamp {
    len: u64,
    modified: std::time::SystemTime,
    #[cfg(unix)]
    identity: (u64, u64, i64, i64),
}

fn candidate_stamp(metadata: &fs::Metadata) -> Result<CandidateStamp, String> {
    require(
        metadata.is_file(),
        "candidate must be a regular file (no symlink)",
    )?;
    Ok(CandidateStamp {
        len: metadata.len(),
        modified: metadata.modified().map_err(|e| e.to_string())?,
        #[cfg(unix)]
        identity: {
            use std::os::unix::fs::MetadataExt;
            (
                metadata.dev(),
                metadata.ino(),
                metadata.ctime(),
                metadata.ctime_nsec(),
            )
        },
    })
}

#[derive(PartialEq, Eq)]
struct CandidateSnapshot {
    canonical: PathBuf,
    stamp: CandidateStamp,
    sha256: String,
}

fn candidate_snapshot(candidate: &Path) -> Result<CandidateSnapshot, String> {
    use sha2::{Digest, Sha256};
    use std::io::Read;

    let metadata = fs::symlink_metadata(candidate).map_err(|e| e.to_string())?;
    let stamp = candidate_stamp(&metadata)?;
    let canonical = fs::canonicalize(candidate).map_err(|e| e.to_string())?;
    let mut file = fs::File::open(candidate).map_err(|e| e.to_string())?;
    require(
        candidate_stamp(&file.metadata().map_err(|e| e.to_string())?)? == stamp,
        "candidate changed while opening",
    )?;
    let mut hasher = Sha256::new();
    let mut buffer = [0u8; 65536];
    loop {
        let count = file.read(&mut buffer).map_err(|e| e.to_string())?;
        if count == 0 {
            break;
        }
        hasher.update(&buffer[..count]);
    }
    require(
        candidate_stamp(&file.metadata().map_err(|e| e.to_string())?)? == stamp
            && candidate_stamp(&fs::symlink_metadata(candidate).map_err(|e| e.to_string())?)?
                == stamp
            && fs::canonicalize(candidate).map_err(|e| e.to_string())? == canonical,
        "candidate changed while hashing",
    )?;
    Ok(CandidateSnapshot {
        canonical,
        stamp,
        sha256: format!("{:x}", hasher.finalize()),
    })
}

fn verify_candidate(
    candidate: &Path,
    verification: impl FnOnce() -> Result<Value, String>,
) -> Result<Value, String> {
    let before = candidate_snapshot(candidate)?;
    let mut report = verification()?;
    let expected = string(&report, "candidate_sha256")?;
    digest(expected)?;
    let after = candidate_snapshot(candidate)?;
    require(
        before == after,
        "candidate changed during evidence verification",
    )?;
    require(before.sha256 == expected, "built candidate SHA256 mismatch")?;
    report["built_candidate_verified"] = json!(true);
    Ok(report)
}

/// Verify the complete bundle and bind its candidate digest to a current file.
/// The candidate is read through a file handle, never loaded or executed. File
/// identity, metadata, canonical path and bytes must stay unchanged across the
/// full verifier. Symlink candidates are rejected, including later substitution.
pub fn verify_with_candidate(
    directory: &Path,
    source: &Path,
    candidate: &Path,
) -> Result<Value, String> {
    verify_candidate(candidate, || verify(directory, source))
}

pub fn verify(directory: &Path, source: &Path) -> Result<Value, String> {
    verify_with_reference_policy(
        directory,
        source,
        "69813961fa007210c4770ea8b4ac0200f851274cb49d761f6dc022a1f2c314d2",
        INCUMBENT_SHA256,
    )
}

// Historical check receipts remain valid. New executions use the exact same
// replay through a worker boundary so outer disposition never enters receipts.
fn validate_fresh_replay_command(
    command: &Value,
    executor: &Value,
    candidate: &Value,
    runtime: &Value,
) -> Result<(), String> {
    let name = match command["arguments"][0].as_str() {
        Some("check") => "check",
        Some("check-worker") => "check-worker",
        _ => return Err("fresh replay command mismatch".into()),
    };
    require(
        command["program"] == executor["path"]
            && command["arguments"]
                == json!([
                    name,
                    "--candidate",
                    candidate["path"],
                    "--receipts",
                    runtime["correctness"]["receipts_path"]
                ]),
        "fresh replay command mismatch",
    )
}

fn verify_with_reference_policy(
    directory: &Path,
    source: &Path,
    reference_hash: &str,
    incumbent_hash: &str,
) -> Result<Value, String> {
    let root = directory.canonicalize().map_err(|e| e.to_string())?;
    let source = source.canonicalize().map_err(|e| e.to_string())?;
    let result = read(&owned_file(&root, Path::new("result.json"))?)?;
    let identity_path = owned_file(&root, Path::new("identity.json"))?;
    let protocol_path = owned_file(&root, Path::new("protocol.json"))?;
    let identity = read(&identity_path)?;
    let protocol = read(&protocol_path)?;
    require(
        result["status"] == "passed"
            && result["protocol"] == PROTOCOL
            && identity["protocol"] == PROTOCOL
            && result["protocol_sha256"] == tooling::file_sha256(&protocol_path)?
            && protocol["identity_sha256"] == tooling::file_sha256(&identity_path)?,
        "altered protocol/identity/result binding",
    )?;
    require(
        identity["os"] == "linux"
            && identity["arch"] == "aarch64"
            && result["architecture_scope"] == "aarch64",
        "qualification architecture is not frozen Linux ARM64",
    )?;
    for (key, expected) in [
        ("seed", 582139),
        ("repetitions", 5),
        ("excluded_outer_warmups", 2),
        ("observations", 11),
        ("php_internal_warmups", 1),
        ("php_samples", 3),
        ("minimum_kernel_batch_ms", 250),
        ("timeout_seconds", 120),
        ("maximum_scheduled_pairs", 5),
        ("chunk_memory_limit", 33554432),
        ("maximum_chunk_slots", 8),
        ("scalar_chunk_slots", 4096),
        ("metadata_slot_limit", 1048576),
    ] {
        require(
            identity[key].as_f64() == Some(expected as f64),
            "altered fixed protocol parameter",
        )?;
    }
    for (key, expected) in [
        ("retry_policy", "hard fail every error; no retries"),
        (
            "batch_results",
            "every scalar witness or every distinct intermediate byte/state witness verified with timer stopped",
        ),
        (
            "elapsed_clock",
            "sum of timed chunk intervals; verification excluded",
        ),
    ] {
        require(identity[key] == expected, "altered protocol semantics")?;
    }
    require(
        protocol["acceptance"]
            == json!({"cpp_large_decode_crc":0.90,"cpp_large_php":0.95,"required_faster_pairs":4,"remaining_pair":1.0,"other_medians":1.05,"all_p95":1.05,"all_maximum_peak_rss":1.05,"cold_lifecycle_median_p95":1.05}),
        "altered acceptance thresholds",
    )?;
    require(
        !root.join("failure.json").exists() && !root.join("gate-failure.json").exists(),
        "bundle retains a failure",
    )?;
    let original = PathBuf::from(string(&result, "output_directory")?);
    require(
        original.is_absolute(),
        "missing original absolute output root",
    )?;
    let child_names = fs::read_dir(root.join("children"))
        .map_err(|e| e.to_string())?
        .map(|entry| {
            let entry = entry.map_err(|e| e.to_string())?;
            require(
                entry.file_type().map_err(|e| e.to_string())?.is_dir(),
                "unexpected file/symlink in children",
            )?;
            entry
                .file_name()
                .into_string()
                .map_err(|_| "non UTF8 child path".into())
        })
        .collect::<Result<BTreeSet<_>, String>>()?;
    let mut bundle = Bundle {
        root,
        original,
        next: 0,
        child_names,
    };
    let runtime = &identity["runtime_manifest"];
    let evidence = array(&identity, "evidence")?;
    require(
        evidence.len() == 4,
        "exact runtime/corpus/replay/reference evidence required",
    )?;
    for item in evidence {
        bundle.artifact(item)?;
    }
    require(
        evidence[3]["sha256"] == reference_hash,
        "altered frozen reference build manifest",
    )?;
    require(
        identity["runtime_manifest_sha256"] == evidence[0]["sha256"]
            && read(&bundle.resolve(
                string(&evidence[0], "path")?,
                string(&evidence[0], "sha256")?,
            )?)? == *runtime,
        "runtime manifest bytes differ from identity",
    )?;
    runtime_sources(runtime, &source)?;
    let artifacts = array(&identity, "artifacts")?;
    require(artifacts.len() == 3, "exact three artifacts required")?;
    for (artifact, name) in artifacts.iter().zip(["candidate", "cpp", "incumbent"]) {
        require(
            artifact["name"] == name && runtime["artifacts"][name]["sha256"] == artifact["sha256"],
            "artifact inventory/runtime mismatch",
        )?;
        bundle.artifact(artifact)?;
    }
    require(
        artifacts[2]["sha256"] == incumbent_hash,
        "altered frozen incumbent artifact",
    )?;
    require(
        artifacts[0]["sha256"] != artifacts[1]["sha256"]
            && artifacts[0]["sha256"] != artifacts[2]["sha256"],
        "candidate reuses a baseline",
    )?;
    for name in ["executor", "php", "php_worker"] {
        bundle.artifact(&identity[name])?;
    }
    require(
        identity["php"]["sha256"] == runtime["php"]["binary_sha256"]
            && identity["php_worker"]["sha256"]
                == runtime["sources"]["sha256"]["scripts/benchmark-yenc.php"],
        "timed PHP binary/caller differs from runtime",
    )?;
    for (index, section, path_key, hash_key) in [
        (1, "correctness", "receipts_path", "receipt_sha256"),
        (2, "correctness", "report_path", "report_sha256"),
        (
            3,
            "reference",
            "build_manifest_path",
            "build_manifest_sha256",
        ),
    ] {
        require(
            evidence[index]["path"] == runtime[section][path_key]
                && evidence[index]["sha256"] == runtime[section][hash_key],
            "external proof differs from runtime",
        )?;
    }
    require(
        runtime["correctness"]["receipt_sha256"] == crate::abi_receipts::FROZEN_RECEIPT_SHA256
            && runtime["correctness"]["calls"] == crate::abi_receipts::FROZEN_REPLAY_CALLS
            && runtime["correctness"]["status"] == "passed",
        "untrusted or abbreviated corpus",
    )?;
    let replay = read(&bundle.resolve(
        string(&evidence[2], "path")?,
        string(&evidence[2], "sha256")?,
    )?)?;
    benchmark::validate_replay_report(
        &replay,
        string(&artifacts[0], "sha256")?,
        crate::abi_receipts::FROZEN_RECEIPT_SHA256,
    )?;
    let reference = read(&bundle.resolve(
        string(&evidence[3], "path")?,
        string(&evidence[3], "sha256")?,
    )?)?;
    benchmark::validate_reference_manifest(&reference, string(&artifacts[1], "sha256")?)?;
    require(
        reference["compiler"] == CPP_COMPILER
            && reference["image_id"] == BUILDER_IMAGE
            && reference["per_source_options"]["src/crc_arm.cc"] == json!(["-march=armv8-a+crc"])
            && reference["per_source_options"]["src/crc_arm_pmull.cc"]
                == json!(["-march=armv8-a+crypto+crc"])
            && reference["per_source_options"]["rapidyenc.cc"] == json!(["-DBUILD_SHARED=1"]),
        "reference compiler/build flags altered",
    )?;
    let fresh = &identity["fresh_correctness_replay"];
    require(
        read(&owned_file(
            &bundle.root,
            Path::new("fresh-correctness-replay.json"),
        )?)? == *fresh,
        "fresh replay differs from identity",
    )?;
    let (fresh_child, command) =
        bundle.child("preflight-full-correctness-replay", &identity["executor"])?;
    validate_fresh_replay_command(&command, &identity["executor"], &artifacts[0], runtime)?;
    require(
        fresh["report"] == fresh_child["report"]
            && fresh["lifetime_ms"] == fresh_child["lifetime_ms"]
            && fresh["peak_rss_bytes"] == fresh_child["peak_rss_bytes"]
            && fresh["child_directory"]
                == json!(
                    bundle
                        .original
                        .join("children/000000-preflight-full-correctness-replay")
                ),
        "fresh replay raw evidence mismatch",
    )?;
    benchmark::validate_replay_report(
        &fresh_child["report"],
        string(&artifacts[0], "sha256")?,
        crate::abi_receipts::FROZEN_RECEIPT_SHA256,
    )?;
    verify_comparisons(&mut bundle, &identity, &protocol, &result, artifacts)
}

struct Scratch(PathBuf);
impl Scratch {
    fn new() -> Result<Self, String> {
        use std::sync::atomic::{AtomicUsize, Ordering};
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let path = std::env::temp_dir().join(format!(
            "nntmux-evidence-{}-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map_err(|e| e.to_string())?
                .as_nanos(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&path).map_err(|e| e.to_string())?;
        Ok(Self(path))
    }
}
impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

fn verify_comparisons(
    bundle: &mut Bundle,
    identity: &Value,
    protocol: &Value,
    result: &Value,
    artifacts: &[Value],
) -> Result<Value, String> {
    // The independent canonical bytes are generated once per verifier process.
    // Only their inventory/hashes survive; no bundle content enters this cache.
    static CANONICAL: std::sync::OnceLock<Result<Vec<Value>, String>> = std::sync::OnceLock::new();
    let canonical = CANONICAL
        .get_or_init(|| {
            let scratch = Scratch::new()?;
            benchmark::evidence_workloads(&scratch.0.join("fixtures"))
        })
        .as_ref()
        .map_err(Clone::clone)?;
    let fixtures = array(identity, "fixtures")?;
    require(
        fixtures.len() == canonical.len(),
        "missing or extra workload inventory",
    )?;
    let mut verified_files = BTreeSet::new();
    for (recorded, expected) in fixtures.iter().zip(canonical) {
        let mut expected = expected.clone();
        for (key, hash_key) in [
            ("data", "data_sha256"),
            ("input", "input_sha256"),
            ("article", "article_sha256"),
        ] {
            let basename = Path::new(string(&expected, key)?)
                .file_name()
                .ok_or("invalid canonical fixture")?;
            expected[key] = json!(bundle.original.join("fixtures").join(basename));
            if verified_files.insert((
                string(recorded, key)?.to_owned(),
                string(recorded, hash_key)?.to_owned(),
            )) {
                bundle.resolve(string(recorded, key)?, string(recorded, hash_key)?)?;
            }
        }
        require(
            *recorded == expected,
            "workload fixture differs from exact canonical bytes/inventory",
        )?;
    }
    let mut expected_comparisons = Vec::new();
    for baseline in ["cpp", "incumbent"] {
        for work in fixtures {
            let php = work["php"] == true;
            let metadata = work["metadata"] == true;
            let supported = php
                || metadata
                || ["decode_ex", "decode_crc", "crc"].contains(&string(work, "operation")?);
            if (baseline == "cpp" && metadata) || (baseline == "incumbent" && !supported) {
                continue;
            }
            expected_comparisons.push((baseline, work));
        }
    }
    require(
        expected_comparisons.len() == 197
            && expected_comparisons
                .iter()
                .filter(|(name, _)| *name == "cpp")
                .count()
                == 128,
        "unexpected canonical comparison count",
    )?;
    let comparisons = array(protocol, "comparisons")?;
    require(comparisons.len() == 197, "missing/extra comparison stratum")?;
    let mut random = 582139;
    for (comparison, (baseline, work)) in comparisons.iter().zip(expected_comparisons) {
        let artifact = &artifacts[if baseline == "cpp" { 1 } else { 2 }];
        require(
            comparison["workload"] == *work && comparison["baseline"] == *artifact,
            "duplicate/missing/reordered comparison stratum",
        )?;
        let iterations = integer(comparison, "iterations")?;
        require(
            iterations > 0 && iterations <= 1_000_000_000 && iterations.is_power_of_two(),
            "invalid frozen calibration count",
        )?;
        let operation = string(work, "operation")?;
        let slots = benchmark::chunk_slots(
            integer(work, "input_bytes")?,
            benchmark::allocated_metadata(operation),
            benchmark::operation_needs_bytes(operation),
        )?;
        require(
            comparison["chunk_slots"] == slots
                && comparison["timed_chunks_per_observation"] == iterations.div_ceil(slots),
            "altered frozen chunk policy",
        )?;
        let mut order = Vec::new();
        for _ in 0..5 {
            let mut pair = ["candidate".to_string(), baseline.to_string()];
            shuffle(&mut pair, &mut random);
            order.push(pair);
        }
        require(
            comparison["variant_order"] == json!(order),
            "altered randomized paired order",
        )?;
        if work["php"] == true {
            require(
                iterations == 1
                    && comparison["expected_receipt"].is_null()
                    && comparison["expected_receipt_sha256"].is_null(),
                "invalid PHP comparison protocol",
            )?;
        } else {
            let expected_path = bundle
                .original
                .join("receipts")
                .join(format!("{baseline}-{}.json", string(work, "id")?));
            require(
                comparison["expected_receipt"] == json!(expected_path),
                "invalid expected receipt path",
            )?;
            let receipt = read(&bundle.resolve(
                string(comparison, "expected_receipt")?,
                string(comparison, "expected_receipt_sha256")?,
            )?)?;
            if [
                "decode",
                "decode_ex",
                "decode_crc",
                "raw_decode_ex",
                "incremental_raw",
            ]
            .contains(&operation)
            {
                require(
                    receipt["count"] == work["size"]
                        && receipt["output_sha256"] == work["data_sha256"],
                    "frozen decode receipt is not fixture-correct",
                )?;
            }
            if ["crc", "decode_crc"].contains(&operation) {
                let data =
                    fs::read(bundle.resolve(string(work, "data")?, string(work, "data_sha256")?)?)
                        .map_err(|e| e.to_string())?;
                require(
                    receipt["crc"] == crc32fast::hash(&data),
                    "frozen CRC receipt is not fixture-correct",
                )?;
            }
            if operation == tooling::FINGERPRINT_OPERATION {
                require(
                    receipt == benchmark::expected_fingerprint_receipt(),
                    "frozen fingerprint transcript is not independently correct",
                )?;
            }
            let frozen = validated_child(
                bundle,
                identity,
                comparison,
                artifact,
                "receipt-freeze",
                1,
                true,
                false,
            )?;
            require(
                frozen["report"]["receipt"] == receipt,
                "frozen expectation differs from original baseline child",
            )?;
            validated_child(
                bundle,
                identity,
                comparison,
                &artifacts[0],
                "receipt-replay",
                1,
                false,
                false,
            )?;
            let mut count = 1;
            loop {
                let baseline_child = validated_child(
                    bundle,
                    identity,
                    comparison,
                    artifact,
                    "calibration",
                    count,
                    false,
                    false,
                )?;
                let candidate_child = validated_child(
                    bundle,
                    identity,
                    comparison,
                    &artifacts[0],
                    "calibration",
                    count,
                    false,
                    false,
                )?;
                let reaches_floor = positive(&baseline_child["report"], "elapsed_ms")? >= 500.
                    && positive(&candidate_child["report"], "elapsed_ms")? >= 500.;
                require(
                    reaches_floor == (count == iterations),
                    "altered calibration stopping point",
                )?;
                if count == iterations {
                    break;
                }
                count = count
                    .checked_mul(2)
                    .filter(|n| *n <= iterations)
                    .ok_or("missing final calibration")?;
            }
        }
    }
    let mut schedule: Vec<(usize, usize)> = (0..197)
        .flat_map(|comparison| (0..5).map(move |repetition| (comparison, repetition)))
        .collect();
    shuffle(&mut schedule, &mut random);
    require(
        protocol["schedule"] == json!(schedule),
        "altered randomized full schedule",
    )?;
    let all_pairs = result["all_pairs"]
        .as_object()
        .ok_or("missing full pairs")?;
    require(all_pairs.len() == 197, "missing/extra pair stratum")?;
    let mut recomputed: BTreeMap<usize, Vec<Value>> = BTreeMap::new();
    let expected_pair_files: BTreeSet<_> = schedule
        .iter()
        .map(|(index, repetition)| format!("pair-{index}-{repetition}.json"))
        .collect();
    let actual_pair_files = fs::read_dir(&bundle.root)
        .map_err(|e| e.to_string())?
        .filter_map(|entry| entry.ok())
        .map(|entry| entry.file_name().to_string_lossy().into_owned())
        .filter(|name| name.starts_with("pair-"))
        .collect::<BTreeSet<_>>();
    require(
        actual_pair_files == expected_pair_files,
        "missing/extra pair evidence files",
    )?;
    for (index, repetition) in schedule {
        let comparison = &comparisons[index];
        let work = &comparison["workload"];
        let baseline = string(&comparison["baseline"], "name")?;
        let order = &array(comparison, "variant_order")?[repetition];
        let mut variants = BTreeMap::new();
        for name in order.as_array().ok_or("invalid variant order")? {
            let name = name.as_str().ok_or("invalid variant name")?;
            let artifact = &artifacts[if name == "candidate" {
                0
            } else if baseline == "cpp" {
                1
            } else {
                2
            }];
            for observation in 0..2 {
                validated_child(
                    bundle,
                    identity,
                    comparison,
                    artifact,
                    &format!("pair{repetition}-warmup{observation}"),
                    integer(comparison, "iterations")?,
                    false,
                    false,
                )?;
            }
            let mut children = Vec::new();
            for observation in 0..11 {
                children.push(validated_child(
                    bundle,
                    identity,
                    comparison,
                    artifact,
                    &format!("pair{repetition}-sample{observation}"),
                    integer(comparison, "iterations")?,
                    false,
                    true,
                )?);
            }
            variants.insert(name, summary(&children, work, artifact)?);
        }
        let candidate = &variants["candidate"];
        let reference = &variants[baseline];
        let ratios = benchmark::ratio(candidate, reference)?;
        let pair = json!({"comparison":index,"repetition":repetition,"workload":work["id"],"baseline":baseline,"variant_order":order,"candidate":candidate,"reference":reference,"ratios":ratios});
        let retained = read(&owned_file(
            &bundle.root,
            Path::new(&format!("pair-{index}-{repetition}.json")),
        )?)?;
        require(
            retained == pair,
            "altered pair timing/RSS/summary/raw child linkage",
        )?;
        recomputed.entry(index).or_default().push(pair);
    }
    require(
        bundle.child_names.is_empty(),
        "extra/unaccounted/failing child evidence",
    )?;
    require(
        result["all_pairs"] == json!(recomputed),
        "duplicate/missing/altered result pairs",
    )?;
    let mut gates = Vec::new();
    for (index, comparison) in comparisons.iter().enumerate() {
        let work = &comparison["workload"];
        let baseline = string(&comparison["baseline"], "name")?;
        let ratios: Vec<_> = recomputed[&index]
            .iter()
            .map(|pair| pair["ratios"].clone())
            .collect();
        let php = work["php"] == true;
        let large = integer(work, "size")? >= 1048576 && baseline == "cpp";
        let improvement = if large && php {
            Some(0.95)
        } else if large && work["operation"] == "decode_crc" {
            Some(0.90)
        } else {
            None
        };
        strict_gate(&ratios, improvement, php)?;
        gates.push(json!({"workload":work["id"],"baseline":baseline,"primary_pass":true,"cold_lifecycle_pass":true,"required_speed_ratio":improvement,"pair_ratios":ratios}));
    }
    require(result["gates"] == json!(gates), "forged gate summaries")?;
    Ok(
        json!({"status":"verified","protocol":PROTOCOL,"comparisons":197,"pairs":985,"retained_children":bundle.next,"architecture_scope":"aarch64","protocol_sha256":result["protocol_sha256"],"candidate_sha256":artifacts[0]["sha256"],"source_inventory_verified":true,"all_gates_recomputed":true}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fresh_replay_command_fixture() -> (Value, Value, Value, Value) {
        let executor = json!({"path":"/owned/qualify-yenc"});
        let candidate = json!({"path":"/owned/candidate.so","sha256":"candidate"});
        let runtime = json!({"correctness":{"receipts_path":"/owned/receipts.json"}});
        let command = json!({"program":executor["path"],"arguments":[
            "check","--candidate",candidate["path"],"--receipts",
            runtime["correctness"]["receipts_path"]
        ]});
        (command, executor, candidate, runtime)
    }

    #[test]
    fn fresh_replay_accepts_historical_command_and_only_known_worker_alias() {
        let (historical, executor, candidate, runtime) = fresh_replay_command_fixture();
        assert!(
            validate_fresh_replay_command(&historical, &executor, &candidate, &runtime).is_ok()
        );
        let mut worker = historical.clone();
        worker["arguments"][0] = json!("check-worker");
        assert!(validate_fresh_replay_command(&worker, &executor, &candidate, &runtime).is_ok());
        assert_eq!(historical["arguments"][0], "check");
    }

    #[test]
    fn fresh_replay_rejects_unknown_extra_reordered_or_mismatched_commands() {
        let (historical, executor, candidate, runtime) = fresh_replay_command_fixture();
        for name in ["check", "check-worker"] {
            let mut original = historical.clone();
            original["arguments"][0] = json!(name);
            for case in [
                "unknown",
                "extra",
                "reordered",
                "candidate",
                "receipts",
                "program",
            ] {
                let mut altered = original.clone();
                match case {
                    "unknown" => altered["arguments"][0] = json!("other-worker"),
                    "extra" => altered["arguments"]
                        .as_array_mut()
                        .unwrap()
                        .push(json!("--extra")),
                    "reordered" => altered["arguments"].as_array_mut().unwrap().swap(1, 3),
                    "candidate" => altered["arguments"][2] = json!("/foreign/candidate.so"),
                    "receipts" => altered["arguments"][4] = json!("/foreign/receipts.json"),
                    "program" => altered["program"] = json!("/foreign/qualify-yenc"),
                    _ => unreachable!(),
                }
                assert_eq!(
                    validate_fresh_replay_command(&altered, &executor, &candidate, &runtime)
                        .unwrap_err(),
                    "fresh replay command mismatch",
                    "{name}: {case}"
                );
            }
        }
    }

    #[test]
    fn fresh_replay_alias_does_not_relax_candidate_or_corpus_hash_checks() {
        // Synthetic historical receipt shape only; this is no current-policy proof.
        let (historical, executor, candidate, runtime) = fresh_replay_command_fixture();
        let report = json!({"status":"ok","artifact":{"sha256":"candidate",
            "rapidyenc_exports":tooling::RAPIDYENC_EXPORTS,
            "metadata_exports":tooling::NNTMUX_EXPORTS},
            "receipt_sha256":crate::abi_receipts::FROZEN_RECEIPT_SHA256,
            "calls":crate::abi_receipts::FROZEN_REPLAY_CALLS});
        for name in ["check", "check-worker"] {
            let mut command = historical.clone();
            command["arguments"][0] = json!(name);
            validate_fresh_replay_command(&command, &executor, &candidate, &runtime).unwrap();
            benchmark::validate_replay_report(
                &report,
                "candidate",
                crate::abi_receipts::FROZEN_RECEIPT_SHA256,
            )
            .unwrap();
            for field in ["candidate", "corpus"] {
                let mut altered = report.clone();
                if field == "candidate" {
                    altered["artifact"]["sha256"] = json!("foreign");
                } else {
                    altered["receipt_sha256"] = json!("foreign");
                }
                assert!(
                    benchmark::validate_replay_report(
                        &altered,
                        "candidate",
                        crate::abi_receipts::FROZEN_RECEIPT_SHA256
                    )
                    .is_err(),
                    "{name}: {field}"
                );
            }
            assert!(report.get("adoption_eligibility").is_none());
        }
    }
    const ABC_SHA256: &str = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad";

    fn candidate_fixture() -> (Scratch, PathBuf) {
        let scratch = Scratch::new().unwrap();
        let candidate = scratch.0.join("candidate.so");
        fs::write(&candidate, b"abc").unwrap();
        (scratch, candidate)
    }

    #[test]
    fn candidate_binding_matches_bytes_and_annotates_only_success() {
        let (_scratch, candidate) = candidate_fixture();
        let report = verify_candidate(&candidate, || {
            Ok(json!({"status":"verified", "candidate_sha256":ABC_SHA256, "pairs":985}))
        })
        .unwrap();
        assert_eq!(report["built_candidate_verified"], true);
        assert_eq!(report["pairs"], 985);
        assert_eq!(fs::read(candidate).unwrap(), b"abc");
    }

    #[test]
    fn candidate_binding_rejects_wrong_expected_hash() {
        let (_scratch, candidate) = candidate_fixture();
        assert!(verify_candidate(&candidate, || Ok(json!({"status":"verified", "candidate_sha256":"0000000000000000000000000000000000000000000000000000000000000000"}))).is_err());
    }

    #[test]
    fn candidate_binding_rejects_missing_or_invalid_expected_digest() {
        let (_scratch, candidate) = candidate_fixture();
        for value in [
            Value::Null,
            json!(1),
            json!(""),
            json!("invalid"),
            json!("BA7816BF8F01CFEA414140DE5DAE2223B00361A396177A9CB410FF61F20015AD"),
        ] {
            assert!(
                verify_candidate(&candidate, || Ok(
                    json!({"status":"verified", "candidate_sha256":value})
                ))
                .is_err()
            );
        }
        assert!(verify_candidate(&candidate, || Ok(json!({"status":"verified"}))).is_err());
    }

    #[test]
    fn candidate_binding_rejects_mutation_during_verification() {
        let (_scratch, candidate) = candidate_fixture();
        assert!(
            verify_candidate(&candidate, || {
                fs::write(&candidate, b"xyz").unwrap();
                Ok(json!({"status":"verified", "candidate_sha256":ABC_SHA256}))
            })
            .is_err()
        );
    }

    #[test]
    fn candidate_binding_rejects_replaced_file_with_matching_bytes() {
        let (scratch, candidate) = candidate_fixture();
        let replacement = scratch.0.join("replacement.so");
        fs::write(&replacement, b"abc").unwrap();
        assert!(
            verify_candidate(&candidate, || {
                fs::rename(&replacement, &candidate).unwrap();
                Ok(json!({"status":"verified", "candidate_sha256":ABC_SHA256}))
            })
            .is_err()
        );
    }

    #[test]
    fn candidate_binding_preserves_full_verifier_failure() {
        let (_scratch, candidate) = candidate_fixture();
        assert_eq!(
            verify_candidate(&candidate, || Err("source inventory mismatch".into())).unwrap_err(),
            "source inventory mismatch"
        );
    }

    #[test]
    fn candidate_binding_rejects_missing_and_nonregular_files() {
        let scratch = Scratch::new().unwrap();
        for candidate in [&scratch.0, &scratch.0.join("missing")] {
            assert!(
                verify_candidate(candidate, || Ok(
                    json!({"status":"verified", "candidate_sha256":ABC_SHA256})
                ))
                .is_err()
            );
        }
    }

    #[cfg(unix)]
    #[test]
    fn candidate_binding_rejects_symlink_and_symlink_substitution() {
        let (scratch, candidate) = candidate_fixture();
        let link = scratch.0.join("link.so");
        std::os::unix::fs::symlink(&candidate, &link).unwrap();
        assert!(
            verify_candidate(&link, || Ok(
                json!({"status":"verified", "candidate_sha256":ABC_SHA256})
            ))
            .is_err()
        );
        assert!(
            verify_candidate(&candidate, || {
                fs::remove_file(&link).unwrap();
                fs::remove_file(&candidate).unwrap();
                fs::write(&link, b"abc").unwrap();
                std::os::unix::fs::symlink(&link, &candidate).unwrap();
                Ok(json!({"status":"verified", "candidate_sha256":ABC_SHA256}))
            })
            .is_err()
        );
    }

    #[test]
    fn duplicate_object_keys_cannot_hide_duplicate_strata() {
        assert!(parse(br#"{"all_pairs":{"0":[],"0":[]}}"#).is_err());
        assert!(parse(br#"{"status":"ok","status":"failed"}"#).is_err());
        assert_eq!(
            parse(br#"{"a":[1,2.0,null,false,"s"]}"#).unwrap(),
            json!({"a":[1,2.0,null,false,"s"]})
        );
    }
    #[test]
    fn rejects_forged_passing_gates_and_missing_pairs() {
        let pairs = vec![
            json!({"median_ratio":1.06,"p95_ratio":1.,"rss_ratio":1.,"lifecycle_median_ratio":1.,"lifecycle_p95_ratio":1.});
            5
        ];
        assert!(strict_gate(&pairs, None, false).is_err());
        assert!(strict_gate(&pairs[..4], None, false).is_err());
    }
    #[test]
    fn enforces_large_improvements_and_cold_php_lifetimes() {
        let mut pairs = vec![
            json!({"median_ratio":0.9,"p95_ratio":1.05,"rss_ratio":1.05,"lifecycle_median_ratio":1.05,"lifecycle_p95_ratio":1.05});
            5
        ];
        assert!(strict_gate(&pairs, Some(0.9), true).is_ok());
        pairs[0]["lifecycle_p95_ratio"] = json!(1.051);
        assert!(strict_gate(&pairs, Some(0.9), true).is_err());
    }
}

#[cfg(test)]
const TEST_REFERENCE: &str = r###"{
  "status": "reference_build_only",
  "parity_qualified": false,
  "speedup_measured": false,
  "source_commit": "480bd7b5896f8b3edecc721d23f1384d767ffe2f",
  "source_archive_sha256": "bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16",
  "image_id": "sha256:93ce27a88655056a51dbdd8f5f2d7ddc071c7b0070fb288a37b5a285fc83971e",
  "platform": "linux/arm64",
  "compiler": "Debian GCC 12.2.0-14+deb12u1",
  "build_method": "manual commands derived from pinned CMake ARM64 Release configuration",
  "build_native": false,
  "disable_encode": true,
  "disable_tool": true,
  "common_options": [
    "-std=gnu++98", "-O3", "-DNDEBUG", "-fPIC", "-Wall", "-Wextra",
    "-Wno-unused-function", "-fno-exceptions", "-fwrapv",
    "-Wno-expansion-to-defined", "-fvisibility=hidden", "-fno-rtti",
    "-fomit-frame-pointer", "-DRAPIDYENC_DISABLE_ENCODE=1",
    "-DCRCUTIL_USE_MM_CRC32=0", "-Icrcutil-1.0/code",
    "-Icrcutil-1.0/examples", "-Icrcutil-1.0/tests"
  ],
  "sources": [
    "src/platform.cc", "src/decoder.cc", "src/decoder_sse2.cc",
    "src/decoder_ssse3.cc", "src/decoder_avx.cc", "src/decoder_avx2.cc",
    "src/decoder_vbmi2.cc", "src/decoder_neon64.cc", "src/decoder_rvv.cc",
    "src/crc.cc", "src/crc_folding.cc", "src/crc_folding_256.cc",
    "src/crc_arm.cc", "src/crc_arm_pmull.cc", "src/crc_riscv.cc",
    "crcutil-1.0/code/crc32c_sse4.cc",
    "crcutil-1.0/code/multiword_64_64_cl_i386_mmx.cc",
    "crcutil-1.0/code/multiword_64_64_gcc_amd64_asm.cc",
    "crcutil-1.0/code/multiword_64_64_gcc_i386_mmx.cc",
    "crcutil-1.0/code/multiword_64_64_intrinsic_i386_mmx.cc",
    "crcutil-1.0/code/multiword_128_64_gcc_amd64_sse2.cc",
    "crcutil-1.0/examples/interface.cc", "rapidyenc.cc"
  ],
  "per_source_options": {
    "src/crc_arm.cc": ["-march=armv8-a+crc"],
    "src/crc_arm_pmull.cc": ["-march=armv8-a+crypto+crc"],
    "rapidyenc.cc": ["-DBUILD_SHARED=1"]
  },
  "feature_probes": { "arm_crc": "passed", "arm_crypto_crc": "passed" },
  "library_sha256": "a62c3da9f4713ee1f73816701e05f9c148c49098a6207d087a6cd5be29a72fd8",
  "public_rapidyenc_export_count": 16,
  "runtime_kernel_selection": {
    "decoder": "NEON",
    "crc32": "ARM-CRC + PMULL"
  },
  "kernel_probe_source": "unmodified pinned tool/bench.cc",
  "kernel_probe_purpose": "dispatch_confirmation_only",
  "kernel_probe_log": "upstream-kernel-probe.log",
  "comparative_performance_qualified": false,
  "production_installed": false
}
"###;

#[cfg(test)]
mod complete_tests {
    use super::*;
    use sha2::{Digest, Sha256};
    fn write(path: &Path, value: &Value) {
        fs::write(path, serde_json::to_vec(value).unwrap()).unwrap();
    }
    fn hash(bytes: &[u8]) -> String {
        format!("{:x}", Sha256::digest(bytes))
    }
    fn verify_test_bundle(directory: &Path, source: &Path) -> Result<Value, String> {
        let identity = read(&directory.join("identity.json"))?;
        verify_with_reference_policy(
            directory,
            source,
            string(&identity["evidence"][3], "sha256")?,
            &hash(b"incumbent"),
        )
    }
    struct Fixture {
        scratch: Scratch,
        bundle: PathBuf,
        source: PathBuf,
        identity: Value,
        protocol: Value,
        result: Value,
        next: usize,
    }
    impl Fixture {
        fn object(&self, bytes: &[u8]) -> String {
            let hash = hash(bytes);
            fs::write(self.bundle.join("objects").join(&hash), bytes).unwrap();
            hash
        }
        fn artifact(&self, name: &str) -> Value {
            json!({"name":name,"path":format!("/external/{name}"),"sha256":self.object(name.as_bytes())})
        }
        fn json_object(&self, value: &Value, path: &str) -> Value {
            json!({"path":path,"sha256":self.object(&serde_json::to_vec(value).unwrap())})
        }
        fn child(
            &mut self,
            suffix: &str,
            report: Value,
            program: Value,
            arguments: Value,
            lifetime: f64,
        ) -> Value {
            let dir = self
                .bundle
                .join("children")
                .join(format!("{:06}-{suffix}", self.next));
            self.next += 1;
            fs::create_dir(&dir).unwrap();
            fs::write(dir.join("stdout"), serde_json::to_vec(&report).unwrap()).unwrap();
            fs::write(dir.join("stderr"), b"").unwrap();
            let conditions = json!({"arch":"aarch64","os":"linux","unix_time_ms":1000});
            write(
                &dir.join("command.json"),
                &json!({"program":program,"arguments":arguments,"timeout_ms":120000,"rss_method":"wait4","os":"linux","machine_conditions":conditions}),
            );
            write(
                &dir.join("outcome.json"),
                &json!({"status":"reported","wait_status":0,"exit_code":0,"signal":0,"timeout":false,"lifetime_ms":lifetime,"peak_rss_native":100,"peak_rss_bytes":102400,"rss_units":"KiB","report":report,"parse_error":null,"stderr_bytes":0,"machine_conditions":conditions}),
            );
            let pid = (self.next * 2 + 100) as u64;
            fs::write(dir.join("worker.pid"), pid.to_string()).unwrap();
            write(
                &dir.join("capture-request.json"),
                &json!({"program":program,"arguments":arguments,"cwd":null,"environment":{},"timeout_ms":120000}),
            );
            let collector = dir.join("collector");
            fs::create_dir(&collector).unwrap();
            fs::write(collector.join("worker.pid"), (pid + 1).to_string()).unwrap();
            let original = Path::new("/evidence/children").join(dir.file_name().unwrap());
            write(
                &collector.join("command.json"),
                &json!({"program":"/external/executor","arguments":["capture-child","--request",original.join("capture-request.json"),"--directory",original],"timeout_ms":125000,"rss_method":"wait4","os":"linux","machine_conditions":conditions}),
            );
            let collector_report = json!({"status":"ok","worker_pid":pid,"worker_outcome_sha256":tooling::file_sha256(&dir.join("outcome.json")).unwrap(),"worker_stdout_sha256":tooling::file_sha256(&dir.join("stdout")).unwrap(),"worker_stderr_sha256":tooling::file_sha256(&dir.join("stderr")).unwrap(),"worker_lifetime_ms":lifetime,"worker_peak_rss_bytes":102400});
            write(&collector.join("stdout"), &collector_report);
            fs::write(collector.join("stderr"), b"").unwrap();
            let collector_outcome = json!({"status":"reported","wait_status":0,"exit_code":0,"signal":0,"timeout":false,"lifetime_ms":lifetime+1.,"peak_rss_native":100000,"peak_rss_bytes":102400000,"rss_units":"KiB","report":collector_report,"parse_error":null,"stderr_bytes":0,"machine_conditions":conditions});
            write(&collector.join("outcome.json"), &collector_outcome);
            write(
                &dir.join("collector.json"),
                &json!({"rss_role":"diagnostic_inherited_parent_floor","worker_pid":pid,"collector_outcome":collector_outcome,"error":null}),
            );
            json!({"lifetime_ms":lifetime,"peak_rss_bytes":102400,"report":report})
        }
        fn expected_receipt(&self, work: &Value) -> Value {
            if work["operation"] == tooling::FINGERPRINT_OPERATION {
                return benchmark::expected_fingerprint_receipt();
            }
            let crc = if ["crc", "decode_crc"].contains(&string(work, "operation").unwrap()) {
                crc32fast::hash(
                    &fs::read(
                        self.bundle.join("fixtures").join(
                            Path::new(string(work, "data").unwrap())
                                .file_name()
                                .unwrap(),
                        ),
                    )
                    .unwrap(),
                )
            } else {
                0
            };
            json!({"output_sha256":work["data_sha256"],"count":work["size"],"fixture_correct":true,"number":0,"crc":crc,"diagnostic":false})
        }
        fn observation(
            &mut self,
            comparison: &Value,
            artifact: &Value,
            phase: &str,
            iterations: usize,
            freeze: bool,
            calibration: bool,
        ) -> Value {
            let work = &comparison["workload"];
            let php = work["php"] == true;
            let candidate = artifact["name"] == "candidate";
            let elapsed = if calibration {
                600.
            } else if candidate {
                270.
            } else {
                300.
            };
            let report = if php {
                let time = if candidate { 90. } else { 100. };
                let observations: Vec<_> = (0..4).map(|i|json!({"sample":i-1,"warmup":i==0,"correct":true,"decoded_bytes":work["size"],"decoded_sha256":work["data_sha256"],"elapsed_ms":time})).collect();
                json!({"status":"ok","library_sha256":artifact["sha256"],"decoder_version":"1.3","peak_rss_bytes":102400,"min_ms":time,"median_ms":time,"max_ms":time,"observations":observations})
            } else {
                let receipt = if freeze {
                    self.expected_receipt(work)
                } else {
                    read(
                        &self.bundle.join("receipts").join(
                            Path::new(string(comparison, "expected_receipt").unwrap())
                                .file_name()
                                .unwrap(),
                        ),
                    )
                    .unwrap()
                };
                json!({"status":"ok","operation":work["operation"],"iterations":iterations,"elapsed_ms":elapsed,"library_sha256":artifact["sha256"],"input_sha256":work["input_sha256"],"data_sha256":work["data_sha256"],"receipt":receipt,"expected_sha256":if freeze {Value::Null} else {comparison["expected_receipt_sha256"].clone()},"batch_results":{"count":iterations,"value":(integer(&receipt,"count").unwrap() as u64).wrapping_add((integer(&receipt,"crc").unwrap() as u64)<<32).wrapping_add(integer(&receipt,"number").unwrap() as u64),"encoding":"one run; every scalar or byte/state witness checked"},"os":"linux","arch":"aarch64","version":if artifact["name"] == "cpp" {0x010101} else {0x0103},"timed_chunks":comparison["timed_chunks_per_observation"],"chunk_slots":comparison["chunk_slots"],"chunk_memory_limit":33554432,"maximum_chunk_slots":8,"scalar_chunk_slots":4096,"decoder_kernel":4096,"crc_kernel":72})
            };
            let suffix = format!(
                "{phase}-{}-{}",
                string(artifact, "name").unwrap(),
                string(work, "id").unwrap()
            );
            let program = self.identity[if php { "php" } else { "executor" }]["path"].clone();
            let args = expected_arguments(
                &self.identity,
                artifact,
                work,
                comparison,
                iterations,
                freeze,
            )
            .unwrap();
            self.child(
                &suffix,
                report,
                program,
                args,
                if calibration { 700. } else { 430. },
            )
        }
        fn build() -> Self {
            let scratch = Scratch::new().unwrap();
            let bundle = scratch.0.join("bundle");
            let source = scratch.0.join("source");
            for path in [
                bundle.join("objects"),
                bundle.join("children"),
                bundle.join("receipts"),
                source.join(CRATE).join("src"),
            ] {
                fs::create_dir_all(path).unwrap();
            }
            let mut fixture = Self {
                scratch,
                bundle,
                source,
                identity: Value::Null,
                protocol: Value::Null,
                result: Value::Null,
                next: 0,
            };
            fs::write(
                fixture.source.join(CRATE).join("src/lib.rs"),
                b"synthetic measured source",
            )
            .unwrap();
            let inventory = source_inventory(&fixture.source).unwrap();
            let mut sources = BTreeMap::new();
            for path in inventory {
                let file = fixture.source.join(&path);
                fs::create_dir_all(file.parent().unwrap()).unwrap();
                if path == format!("{CRATE}/Cargo.toml") {
                    fs::write(
                        &file,
                        b"[profile.release]\nopt-level = 3\nlto = true\ncodegen-units = 1\n",
                    )
                    .unwrap();
                } else {
                    fs::write(&file, path.as_bytes()).unwrap();
                }
                sources.insert(path, tooling::file_sha256(&file).unwrap());
            }
            let artifacts = vec![
                fixture.artifact("candidate"),
                fixture.artifact("cpp"),
                fixture.artifact("incumbent"),
            ];
            // The real frozen manifest binds a fixed reference binary hash; the
            // synthetic library bytes are never executed and retain their own hash.
            let mut reference: Value = serde_json::from_str(TEST_REFERENCE).unwrap();
            reference["library_sha256"] = artifacts[1]["sha256"].clone();
            let reference_proof = fixture.json_object(&reference, "/reference/build.json");
            let corpus = include_bytes!("../fixtures/rapidyenc-abi-v1.json");
            assert_eq!(
                fixture.object(corpus),
                crate::abi_receipts::FROZEN_RECEIPT_SHA256
            );
            let replay = json!({"status":"ok","artifact":{"sha256":artifacts[0]["sha256"],"rapidyenc_exports":tooling::RAPIDYENC_EXPORTS,"metadata_exports":tooling::NNTMUX_EXPORTS},"receipt_sha256":crate::abi_receipts::FROZEN_RECEIPT_SHA256,"calls":crate::abi_receipts::FROZEN_REPLAY_CALLS});
            let replay_proof = fixture.json_object(&replay, "/candidate/replay.json");
            let php = fixture.artifact("php");
            let worker_hash = sources["scripts/benchmark-yenc.php"].clone();
            fixture.object(b"scripts/benchmark-yenc.php");
            let worker =
                json!({"name":"php_worker","path":"/source/worker.php","sha256":worker_hash});
            let mut runtime = json!({"protocol":PROTOCOL,"sources":{"sha256":sources,"incumbent_fork_master":INCUMBENT_COMMIT},"lockfile":{"cargo_sha256":sources[&format!("{CRATE}/Cargo.lock")],"composer_sha256":sources["composer.lock"]},"vendor":{"installed_json_sha256":sources["vendor/composer/installed.json"]},"compilers":{"rust":RUST_COMPILER,"cpp":CPP_COMPILER},"image_ids":{"builder":BUILDER_IMAGE,"php":PHP_IMAGE},"build_flags":{"profile":{"opt_level":3,"lto":true,"codegen_units":1},"build_native":false,"candidate":["cargo test --release --locked --offline","cargo build --release --locked --offline"],"incumbent":["cargo test --release --locked --offline","cargo build --release --locked --offline"]},"php":{"binary_sha256":php["sha256"],"sapi":"cli","ffi":"1","opcache_cli":"0","jit":"disable"},"extensions":[],"cpu":{"linux_arch":"aarch64"},"machine_conditions":{},"artifacts":{},"correctness":{"status":"passed","receipts_path":"/external/corpus.json","receipt_sha256":crate::abi_receipts::FROZEN_RECEIPT_SHA256,"report_path":replay_proof["path"],"report_sha256":replay_proof["sha256"],"calls":crate::abi_receipts::FROZEN_REPLAY_CALLS},"reference":{"build_manifest_path":reference_proof["path"],"build_manifest_sha256":reference_proof["sha256"]}});
            for artifact in &artifacts {
                runtime["artifacts"][string(artifact, "name").unwrap()] =
                    json!({"sha256":artifact["sha256"]});
            }
            let runtime_proof = fixture.json_object(&runtime, "/external/runtime.json");
            let evidence = vec![
                runtime_proof.clone(),
                json!({"path":"/external/corpus.json","sha256":crate::abi_receipts::FROZEN_RECEIPT_SHA256}),
                replay_proof,
                reference_proof,
            ];
            let executor = fixture.artifact("executor");
            let fresh_child = fixture.child(
                "preflight-full-correctness-replay",
                replay,
                executor["path"].clone(),
                json!([
                    "check",
                    "--candidate",
                    artifacts[0]["path"],
                    "--receipts",
                    "/external/corpus.json"
                ]),
                1000.,
            );
            let fresh = json!({"report":fresh_child["report"],"lifetime_ms":fresh_child["lifetime_ms"],"peak_rss_bytes":fresh_child["peak_rss_bytes"],"child_directory":"/evidence/children/000000-preflight-full-correctness-replay"});
            write(
                &fixture.bundle.join("fresh-correctness-replay.json"),
                &fresh,
            );
            let mut works =
                benchmark::evidence_workloads(&fixture.bundle.join("fixtures")).unwrap();
            for work in &mut works {
                for key in ["data", "input", "article"] {
                    work[key] = json!(
                        Path::new("/evidence/fixtures")
                            .join(Path::new(string(work, key).unwrap()).file_name().unwrap())
                    );
                }
            }
            fixture.identity = json!({"protocol":PROTOCOL,"runtime_manifest":runtime,"runtime_manifest_sha256":runtime_proof["sha256"],"fresh_correctness_replay":fresh,"evidence":evidence,"artifacts":artifacts,"executor":executor,"php":php,"php_worker":worker,"os":"linux","arch":"aarch64","seed":582139,"repetitions":5,"excluded_outer_warmups":2,"observations":11,"php_internal_warmups":1,"php_samples":3,"minimum_kernel_batch_ms":250,"timeout_seconds":120,"maximum_scheduled_pairs":5,"chunk_memory_limit":33554432,"maximum_chunk_slots":8,"scalar_chunk_slots":4096,"metadata_slot_limit":1048576,"retry_policy":"hard fail every error; no retries","batch_results":"every scalar witness or every distinct intermediate byte/state witness verified with timer stopped","elapsed_clock":"sum of timed chunk intervals; verification excluded","fixtures":works});
            let mut comparisons = Vec::new();
            let mut random = 582139;
            for artifact in &artifacts[1..] {
                let baseline = string(artifact, "name").unwrap();
                for work in &works {
                    let php = work["php"] == true;
                    let metadata = work["metadata"] == true;
                    if (baseline == "cpp" && metadata)
                        || (baseline == "incumbent"
                            && !(php
                                || metadata
                                || ["decode_ex", "decode_crc", "crc"]
                                    .contains(&string(work, "operation").unwrap())))
                    {
                        continue;
                    }
                    let operation = string(work, "operation").unwrap();
                    let slots = benchmark::chunk_slots(
                        integer(work, "input_bytes").unwrap(),
                        benchmark::allocated_metadata(operation),
                        benchmark::operation_needs_bytes(operation),
                    )
                    .unwrap();
                    let expected_path = format!(
                        "/evidence/receipts/{baseline}-{}.json",
                        string(work, "id").unwrap()
                    );
                    let expected = fixture.expected_receipt(work);
                    let expected_hash = if php {
                        Value::Null
                    } else {
                        write(
                            &fixture
                                .bundle
                                .join("receipts")
                                .join(Path::new(&expected_path).file_name().unwrap()),
                            &expected,
                        );
                        json!(hash(&serde_json::to_vec(&expected).unwrap()))
                    };
                    let mut order = Vec::new();
                    for _ in 0..5 {
                        let mut pair = ["candidate".to_string(), baseline.to_string()];
                        shuffle(&mut pair, &mut random);
                        order.push(pair);
                    }
                    let comparison = json!({"workload":work,"baseline":artifact,"iterations":1,"expected_receipt":if php {Value::Null} else {json!(expected_path)},"expected_receipt_sha256":expected_hash,"chunk_slots":slots,"timed_chunks_per_observation":1,"variant_order":order});
                    if !php {
                        fixture.observation(
                            &comparison,
                            artifact,
                            "receipt-freeze",
                            1,
                            true,
                            false,
                        );
                        fixture.observation(
                            &comparison,
                            &artifacts[0],
                            "receipt-replay",
                            1,
                            false,
                            false,
                        );
                        fixture.observation(&comparison, artifact, "calibration", 1, false, true);
                        fixture.observation(
                            &comparison,
                            &artifacts[0],
                            "calibration",
                            1,
                            false,
                            true,
                        );
                    }
                    comparisons.push(comparison);
                }
            }
            let mut schedule: Vec<(usize, usize)> = (0..197)
                .flat_map(|index| (0..5).map(move |rep| (index, rep)))
                .collect();
            shuffle(&mut schedule, &mut random);
            let mut pairs: BTreeMap<usize, Vec<Value>> = BTreeMap::new();
            for &(index, rep) in &schedule {
                let comparison = &comparisons[index];
                let baseline = string(&comparison["baseline"], "name").unwrap();
                let order = &comparison["variant_order"][rep];
                let mut variants = BTreeMap::new();
                for name in order.as_array().unwrap() {
                    let name = name.as_str().unwrap();
                    let artifact = &artifacts[if name == "candidate" {
                        0
                    } else if baseline == "cpp" {
                        1
                    } else {
                        2
                    }];
                    for observation in 0..2 {
                        fixture.observation(
                            comparison,
                            artifact,
                            &format!("pair{rep}-warmup{observation}"),
                            1,
                            false,
                            false,
                        );
                    }
                    let children: Vec<_> = (0..11)
                        .map(|observation| {
                            fixture.observation(
                                comparison,
                                artifact,
                                &format!("pair{rep}-sample{observation}"),
                                1,
                                false,
                                false,
                            )
                        })
                        .collect();
                    variants.insert(
                        name,
                        summary(&children, &comparison["workload"], artifact).unwrap(),
                    );
                }
                let ratios = benchmark::ratio(&variants["candidate"], &variants[baseline]).unwrap();
                let pair = json!({"comparison":index,"repetition":rep,"workload":comparison["workload"]["id"],"baseline":baseline,"variant_order":order,"candidate":variants["candidate"],"reference":variants[baseline],"ratios":ratios});
                write(
                    &fixture.bundle.join(format!("pair-{index}-{rep}.json")),
                    &pair,
                );
                pairs.entry(index).or_default().push(pair);
            }
            let mut gates = Vec::new();
            for (index, comparison) in comparisons.iter().enumerate() {
                let work = &comparison["workload"];
                let baseline = string(&comparison["baseline"], "name").unwrap();
                let large = integer(work, "size").unwrap() >= 1048576 && baseline == "cpp";
                let improvement = if large && work["php"] == true {
                    Some(0.95)
                } else if large && work["operation"] == "decode_crc" {
                    Some(0.90)
                } else {
                    None
                };
                let ratios: Vec<_> = pairs[&index]
                    .iter()
                    .map(|pair| pair["ratios"].clone())
                    .collect();
                gates.push(json!({"workload":work["id"],"baseline":baseline,"primary_pass":true,"cold_lifecycle_pass":true,"required_speed_ratio":improvement,"pair_ratios":ratios}));
            }
            fixture.protocol = json!({"comparisons":comparisons,"schedule":schedule,"acceptance":{"cpp_large_decode_crc":0.90,"cpp_large_php":0.95,"required_faster_pairs":4,"remaining_pair":1.0,"other_medians":1.05,"all_p95":1.05,"all_maximum_peak_rss":1.05,"cold_lifecycle_median_p95":1.05}});
            fixture.result = json!({"status":"passed","protocol":PROTOCOL,"all_pairs":pairs,"gates":gates,"architecture_scope":"aarch64","output_directory":"/evidence"});
            fixture.seal();
            fixture
        }
        fn seal(&mut self) {
            let proof =
                self.json_object(&self.identity["runtime_manifest"], "/external/runtime.json");
            self.identity["runtime_manifest_sha256"] = proof["sha256"].clone();
            self.identity["evidence"][0] = proof;
            write(&self.bundle.join("identity.json"), &self.identity);
            self.protocol["identity_sha256"] =
                json!(tooling::file_sha256(&self.bundle.join("identity.json")).unwrap());
            write(&self.bundle.join("protocol.json"), &self.protocol);
            self.result["protocol_sha256"] =
                json!(tooling::file_sha256(&self.bundle.join("protocol.json")).unwrap());
            write(&self.bundle.join("result.json"), &self.result);
        }
        fn refresh_collector(child: &Path) {
            let worker = read(&child.join("outcome.json")).unwrap();
            let collector = child.join("collector");
            let mut report = read(&collector.join("stdout")).unwrap();
            for (key, file) in [
                ("worker_outcome_sha256", "outcome.json"),
                ("worker_stdout_sha256", "stdout"),
                ("worker_stderr_sha256", "stderr"),
            ] {
                report[key] = json!(tooling::file_sha256(&child.join(file)).unwrap());
            }
            report["worker_lifetime_ms"] = worker["lifetime_ms"].clone();
            report["worker_peak_rss_bytes"] = worker["peak_rss_bytes"].clone();
            let mut outcome = read(&collector.join("outcome.json")).unwrap();
            outcome["report"] = report.clone();
            write(&collector.join("stdout"), &report);
            write(&collector.join("outcome.json"), &outcome);
            let mut parent = read(&child.join("collector.json")).unwrap();
            parent["collector_outcome"] = outcome;
            write(&child.join("collector.json"), &parent);
        }
    }
    fn validate_native_version_child(role: &str, version: u64) -> Result<Value, String> {
        let scratch = Scratch::new().unwrap();
        let bundle = scratch.0.join("bundle");
        for path in [bundle.join("objects"), bundle.join("children")] {
            fs::create_dir_all(path).unwrap();
        }
        let mut fixture = Fixture {
            source: scratch.0.join("unused-source"),
            scratch,
            bundle,
            identity: Value::Null,
            protocol: Value::Null,
            result: Value::Null,
            next: 0,
        };
        let executor = fixture.artifact("executor");
        let artifact = fixture.artifact(role);
        fixture.identity = json!({"executor":executor});
        let fixture_sha256 = hash(b"abc");
        let comparison = json!({
            "workload":{
                "id":"native-version", "operation":"decode", "php":false,
                "size":3, "input_bytes":3,
                "data":"/evidence/fixtures/data", "input":"/evidence/fixtures/input",
                "data_sha256":fixture_sha256, "input_sha256":fixture_sha256
            },
            "chunk_slots":8, "timed_chunks_per_observation":1
        });
        fixture.observation(&comparison, &artifact, "freeze", 1, true, false);
        let name = format!("000000-freeze-{role}-native-version");
        let child = fixture.bundle.join("children").join(&name);
        let mut outcome = read(&child.join("outcome.json")).unwrap();
        outcome["report"]["version"] = json!(version);
        write(&child.join("stdout"), &outcome["report"]);
        write(&child.join("outcome.json"), &outcome);
        Fixture::refresh_collector(&child);
        let mut retained = Bundle {
            root: fixture.bundle.clone(),
            original: PathBuf::from("/evidence"),
            next: 0,
            child_names: BTreeSet::from([name]),
        };

        validated_child(
            &mut retained,
            &fixture.identity,
            &comparison,
            &artifact,
            "freeze",
            1,
            true,
            true,
        )
    }

    #[test]
    fn native_version_accepts_pinned_cpp_receipt() {
        let child = validate_native_version_child("cpp", 0x010101).unwrap();
        assert_eq!(child["report"]["version"], 65793);
    }

    #[test]
    fn native_version_accepts_candidate_receipt() {
        assert!(validate_native_version_child("candidate", 0x0103).is_ok());
    }

    #[test]
    fn native_version_accepts_incumbent_receipt() {
        assert!(validate_native_version_child("incumbent", 0x0103).is_ok());
    }

    #[test]
    fn native_version_rejects_cpp_receipt_with_rust_version() {
        assert_eq!(
            validate_native_version_child("cpp", 0x0103).unwrap_err(),
            "native child runtime/version mismatch"
        );
    }

    #[test]
    fn native_version_rejects_candidate_receipt_with_cpp_version() {
        assert_eq!(
            validate_native_version_child("candidate", 0x010101).unwrap_err(),
            "native child runtime/version mismatch"
        );
    }

    #[test]
    fn native_version_rejects_incumbent_receipt_with_cpp_version() {
        assert_eq!(
            validate_native_version_child("incumbent", 0x010101).unwrap_err(),
            "native child runtime/version mismatch"
        );
    }

    #[test]
    fn native_version_rejects_unknown_artifact_role() {
        assert_eq!(
            validate_native_version_child("unknown", 0x0103).unwrap_err(),
            "unknown native artifact role"
        );
    }

    #[test]
    fn complete_portable_bundle_and_adversarial_mutations() {
        let mut fixture = Fixture::build();
        // Exercise the full verifier with the real pinned manifest and reference
        // hash policy through a test-only replacement of that one synthetic file.
        let report = verify_test_bundle(&fixture.bundle, &fixture.source).unwrap();
        assert_eq!(report["comparisons"], 197);
        assert_eq!(report["pairs"], 985);
        assert_eq!(report["retained_children"], 26207);
        assert_eq!(
            hash(TEST_REFERENCE.as_bytes()),
            "69813961fa007210c4770ea8b4ac0200f851274cb49d761f6dc022a1f2c314d2"
        );
        let original_result = fixture.result.clone();
        let original_protocol = fixture.protocol.clone();
        let original_identity = fixture.identity.clone();
        for case in [
            "missing_fingerprint",
            "extra_fingerprint",
            "altered_fingerprint_workload",
            "missing_pairs",
            "missing_repetition",
            "duplicate_stratum",
            "forged_summary",
            "threshold",
            "order",
            "source_inventory",
            "compiler",
            "image",
            "lock",
            "source",
        ] {
            match case {
                "missing_fingerprint" => {
                    fixture.protocol["comparisons"]
                        .as_array_mut()
                        .unwrap()
                        .pop();
                }
                "extra_fingerprint" => {
                    let extra = fixture.protocol["comparisons"]
                        .as_array()
                        .unwrap()
                        .last()
                        .unwrap()
                        .clone();
                    fixture.protocol["comparisons"]
                        .as_array_mut()
                        .unwrap()
                        .push(extra);
                }
                "altered_fingerprint_workload" => {
                    fixture.protocol["comparisons"][196]["workload"]["operation"] =
                        json!("nntmux_article_fingerprint_value");
                }
                "missing_pairs" => {
                    fixture.result["all_pairs"]
                        .as_object_mut()
                        .unwrap()
                        .remove("0");
                }
                "missing_repetition" => {
                    fixture.result["all_pairs"]["0"]
                        .as_array_mut()
                        .unwrap()
                        .pop();
                }
                "duplicate_stratum" => {
                    fixture.protocol["comparisons"][1] = fixture.protocol["comparisons"][0].clone();
                }
                "forged_summary" => {
                    fixture.result["all_pairs"]["0"][0]["candidate"]["median_ms"] = json!(1.)
                }
                "threshold" => fixture.protocol["acceptance"]["all_p95"] = json!(10.),
                "order" => {
                    fixture.protocol["schedule"][0] = fixture.protocol["schedule"][1].clone()
                }
                "source_inventory" => {
                    fixture.identity["runtime_manifest"]["sources"]["sha256"]
                        .as_object_mut()
                        .unwrap()
                        .remove(&format!("{CRATE}/src/lib.rs"));
                }
                "compiler" => {
                    fixture.identity["runtime_manifest"]["compilers"]["rust"] = json!("forged")
                }
                "image" => {
                    fixture.identity["runtime_manifest"]["image_ids"]["php"] = json!("forged")
                }
                "lock" => {
                    fixture.identity["runtime_manifest"]["lockfile"]["cargo_sha256"] =
                        json!("forged")
                }
                "source" => {
                    fs::write(fixture.source.join(CRATE).join("src/lib.rs"), b"modified").unwrap()
                }
                _ => unreachable!(),
            }
            fixture.seal();
            assert!(
                verify_test_bundle(&fixture.bundle, &fixture.source).is_err(),
                "accepted {case}"
            );
            if case == "source" {
                fs::write(
                    fixture.source.join(CRATE).join("src/lib.rs"),
                    format!("{CRATE}/src/lib.rs"),
                )
                .unwrap();
            }
            fixture.result = original_result.clone();
            fixture.protocol = original_protocol.clone();
            fixture.identity = original_identity.clone();
            fixture.seal();
        }
        let fingerprint_path = fixture
            .bundle
            .join("receipts")
            .join("incumbent-nntmux_article_fingerprint_new.json");
        let original_fingerprint = read(&fingerprint_path).unwrap();
        for offset in [0, 4, 8, 16, 55, 64, 100, 200, 300, 448] {
            let mut changed = original_fingerprint.clone();
            let byte = changed["fingerprint"]["bytes"][offset].as_u64().unwrap();
            changed["fingerprint"]["bytes"][offset] = json!(byte ^ 1);
            let trace: Vec<u8> = changed["fingerprint"]["bytes"]
                .as_array()
                .unwrap()
                .iter()
                .map(|v| v.as_u64().unwrap() as u8)
                .collect();
            changed["fingerprint"]["sha256"] = json!(hash(&trace));
            write(&fingerprint_path, &changed);
            fixture.protocol["comparisons"][196]["expected_receipt_sha256"] =
                json!(tooling::file_sha256(&fingerprint_path).unwrap());
            fixture.seal();
            let error = verify_test_bundle(&fixture.bundle, &fixture.source).unwrap_err();
            assert!(
                error.contains("fingerprint transcript is not independently correct"),
                "wrong rejection for byte {offset}: {error}"
            );
        }
        write(&fingerprint_path, &original_fingerprint);
        fixture.protocol = original_protocol.clone();
        fixture.seal();
        let child = fs::read_dir(fixture.bundle.join("children"))
            .unwrap()
            .map(Result::unwrap)
            .find(|entry| {
                let name = entry.file_name().to_string_lossy().into_owned();
                name.contains("-sample0-") && !name.contains("-php-")
            })
            .unwrap()
            .path();
        let outcome_path = child.join("outcome.json");
        let outcome = read(&outcome_path).unwrap();
        for case in ["rss", "timing", "failure", "rawoutput", "artifact"] {
            let mut changed = outcome.clone();
            match case {
                "rss" => changed["peak_rss_bytes"] = json!(0),
                "timing" => changed["report"]["elapsed_ms"] = json!(0),
                "failure" => changed["exit_code"] = json!(1),
                "rawoutput" => changed["report"]["receipt"] = json!({"forged":true}),
                "artifact" => changed["report"]["library_sha256"] = json!("forged"),
                _ => unreachable!(),
            }
            write(&outcome_path, &changed);
            write(&child.join("stdout"), &changed["report"]);
            Fixture::refresh_collector(&child);
            assert!(
                verify_test_bundle(&fixture.bundle, &fixture.source).is_err(),
                "accepted {case}"
            );
            write(&outcome_path, &outcome);
            write(&child.join("stdout"), &outcome["report"]);
            Fixture::refresh_collector(&child);
        }
        let collector = child.join("collector.json");
        let original_collector = read(&collector).unwrap();
        let mut altered_collector = original_collector.clone();
        altered_collector["rss_role"] = json!("worker_primary_metric");
        write(&collector, &altered_collector);
        assert!(verify_test_bundle(&fixture.bundle, &fixture.source).is_err());
        write(&collector, &original_collector);
        let request = child.join("capture-request.json");
        let original_request = read(&request).unwrap();
        let mut altered_request = original_request.clone();
        altered_request["environment"] = json!({"RUSTFLAGS":"forged"});
        write(&request, &altered_request);
        assert!(verify_test_bundle(&fixture.bundle, &fixture.source).is_err());
        write(&request, &original_request);
        let library = fixture
            .bundle
            .join("objects")
            .join(string(&fixture.identity["artifacts"][0], "sha256").unwrap());
        fs::write(&library, b"tampered artifact").unwrap();
        assert!(verify_test_bundle(&fixture.bundle, &fixture.source).is_err());
        assert!(fixture.scratch.0.exists());
    }
}
