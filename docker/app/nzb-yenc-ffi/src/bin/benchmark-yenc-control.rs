//! Explicit Rust A/A ABI control; never a C++ comparison or caller adoption gate.
use serde_json::{Value, json};
use std::{fs, path::Path, process::Command, time::Duration};

use sha2::{Digest, Sha256};
use std::os::unix::fs::{DirBuilderExt, OpenOptionsExt};
use std::os::unix::process::CommandExt;
use std::{
    fs::OpenOptions,
    io::{Read, Write},
    process::Stdio,
    thread,
    time::Instant,
};

#[cfg(target_os = "linux")]
type ResourceLimit = std::os::raw::c_ulong;
#[cfg(target_os = "macos")]
type ResourceLimit = u64;
#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct FileLimit {
    current: ResourceLimit,
    maximum: ResourceLimit,
}
unsafe extern "C" {
    fn setrlimit(resource: std::os::raw::c_int, limit: *const FileLimit) -> std::os::raw::c_int;
    #[cfg(test)]
    fn getrlimit(resource: std::os::raw::c_int, limit: *mut FileLimit) -> std::os::raw::c_int;
}
const RETAINED_OUTPUT_CAP: usize = 65_536;

fn digest(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}

fn read_regular(path: &Path, cap: usize) -> Result<Vec<u8>, String> {
    // Nonblocking avoids a swapped FIFO blocking open; NOFOLLOW rejects a
    // symlink at the final component. Metadata is checked on the opened file.
    #[cfg(target_os = "linux")]
    const FLAGS: i32 = 0x20000 | 0x800;
    #[cfg(target_os = "macos")]
    const FLAGS: i32 = 0x100 | 0x4;
    let file = OpenOptions::new()
        .read(true)
        .custom_flags(FLAGS)
        .open(path)
        .map_err(|e| e.to_string())?;
    let metadata = file.metadata().map_err(|e| e.to_string())?;
    if !metadata.is_file() || metadata.len() > cap as u64 {
        return Err("nonregular or oversized file".into());
    }
    let mut bytes = Vec::new();
    file.take(cap as u64 + 1)
        .read_to_end(&mut bytes)
        .map_err(|e| e.to_string())?;
    if bytes.len() > cap {
        return Err("file grew beyond budget".into());
    }
    Ok(bytes)
}

fn exclusive(path: &Path) -> Result<fs::File, String> {
    OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(path)
        .map_err(|e| e.to_string())
}

fn control_order(seed: u64, fixture: &str, operation: &str, sample: u64) -> [&'static str; 2] {
    let input = serde_json::to_vec(&(seed, fixture, operation, sample)).unwrap();
    if Sha256::digest(input)[0] & 1 == 0 {
        ["control_a", "control_b"]
    } else {
        ["control_b", "control_a"]
    }
}

fn run_child(command: &mut Command, prefix: &Path, timeout: Duration) -> Result<Value, String> {
    run_child_mode(command, prefix, timeout, true)
}
fn run_child_mode(
    command: &mut Command,
    prefix: &Path,
    timeout: Duration,
    json_required: bool,
) -> Result<Value, String> {
    let stdout_path = prefix.with_extension("stdout");
    let stderr_path = prefix.with_extension("stderr");
    let stdout = exclusive(&stdout_path)?;
    let stderr = exclusive(&stderr_path)?;
    let argv: Vec<_> = std::iter::once(command.get_program())
        .chain(command.get_args())
        .map(|v| v.to_string_lossy().into_owned())
        .collect();
    command
        .env_clear()
        .env("PATH", "/usr/bin:/bin")
        .env("LANG", "C")
        .env("LC_ALL", "C")
        .env("PYTHONHASHSEED", "0")
        .env("PYTHONDONTWRITEBYTECODE", "1")
        .stdin(Stdio::null())
        .stdout(stdout)
        .stderr(stderr);
    // SAFETY: this closure runs only in the freshly forked child. The two
    // libc calls are async-signal-safe; stack-only limits match native rlim_t.
    // Resource IDs1(FSIZE) and4(CORE) are shared by Linux and macOS headers.
    // This bounds retained files during capture, without changing parent limits.
    unsafe {
        command.pre_exec(|| {
            let file_limit = FileLimit {
                current: RETAINED_OUTPUT_CAP as ResourceLimit,
                maximum: RETAINED_OUTPUT_CAP as ResourceLimit,
            };
            let no_core = FileLimit {
                current: 0,
                maximum: 0,
            };
            if setrlimit(1, &file_limit) != 0 || setrlimit(4, &no_core) != 0 {
                Err(std::io::Error::last_os_error())
            } else {
                Ok(())
            }
        });
    }
    let start = Instant::now();
    let mut status = "ok";
    let mut exit_code = None;
    let mut reaped = false;
    let mut errors = Vec::new();
    match command.spawn() {
        Err(e) => {
            status = "spawn_failed";
            errors.push(format!("spawn: {e}"));
        }
        Ok(mut child) => loop {
            match child.try_wait() {
                Ok(Some(exit)) => {
                    exit_code = exit.code();
                    reaped = true;
                    if !exit.success() {
                        status = "child_failed";
                    }
                    break;
                }
                Ok(None) if start.elapsed() < timeout => {
                    thread::sleep(Duration::from_millis(5));
                    continue;
                }
                Ok(None) => status = "timeout",
                Err(e) => {
                    status = "poll_failed";
                    errors.push(format!("poll: {e}"));
                }
            }
            if let Err(e) = child.kill() {
                errors.push(format!("kill: {e}"));
            }
            match child.wait() {
                Ok(exit) => {
                    reaped = true;
                    exit_code = exit.code();
                }
                Err(e) => errors.push(format!("wait: {e}")),
            }
            break;
        },
    }
    let stdout_bytes = fs::metadata(&stdout_path).map_err(|e| e.to_string())?.len();
    let stderr_bytes = fs::metadata(&stderr_path).map_err(|e| e.to_string())?.len();
    let oversized =
        stdout_bytes >= RETAINED_OUTPUT_CAP as u64 || stderr_bytes >= RETAINED_OUTPUT_CAP as u64;
    let result = if oversized {
        None
    } else {
        match read_regular(&stdout_path, 262_144) {
            Ok(bytes) => serde_json::from_slice::<Value>(&bytes).ok(),
            Err(e) => {
                errors.push(format!("read stdout: {e}"));
                None
            }
        }
    };
    if oversized {
        status = "output_limit_reached";
    } else if status == "ok" {
        status = if stderr_bytes != 0 {
            "child_stderr"
        } else if json_required && result.is_none() {
            "invalid_json"
        } else {
            "ok"
        };
    }
    Ok(
        json!({"status":status,"exit_code":exit_code,"direct_child_reaped":reaped,
        "cleanup_scope":"direct frozen worker only; no process-tree termination claim",
        "errors":errors,"command":argv,"result":result,"stdout_path":stdout_path,"stderr_path":stderr_path,
        "stdout_bytes":stdout_bytes,"stderr_bytes":stderr_bytes,"output_budget_exceeded":oversized,
        "per_child_file_limit_bytes":RETAINED_OUTPUT_CAP,
        "process_elapsed_ns":start.elapsed().as_nanos() as u64}),
    )
}

#[derive(Debug, serde::Serialize)]
struct Fixture {
    name: String,
    data_path: std::path::PathBuf,
    article_path: std::path::PathBuf,
    preparation_manifest_sha256: String,
    data_sha256: String,
    article_sha256: String,
    decoded_bytes: u64,
    crc32: u32,
    crc_seed: u32,
}

fn frozen_file(path: &Path, bytes: &[u8]) -> Result<(), String> {
    let mut file = exclusive(path)?;
    file.write_all(bytes).map_err(|e| e.to_string())?;
    file.flush().map_err(|e| e.to_string())
}

fn freeze_fixture(
    source: &Path,
    output: &Path,
    size: usize,
    density: &str,
) -> Result<Fixture, String> {
    if !(1..=8_388_608).contains(&size) || !matches!(density, "ordinary" | "heavy") {
        return Err("unsupported fixture size or density".into());
    }
    let metadata = fs::symlink_metadata(source).map_err(|e| e.to_string())?;
    if !metadata.is_dir() || metadata.file_type().is_symlink() {
        return Err("fixture directory must be regular".into());
    }
    let article_limit = size
        .checked_mul(2)
        .and_then(|v| v.checked_add(size.div_ceil(64) * 2))
        .and_then(|v| v.checked_add(256))
        .ok_or("article budget overflow")?;
    let raw = read_regular(&source.join("manifest.json"), 4096)?;
    let manifest: Value = serde_json::from_slice(&raw).map_err(|e| e.to_string())?;
    let data = read_regular(&source.join("data"), size)?;
    let article = read_regular(&source.join("article"), article_limit)?;
    let data_sha = digest(&data);
    let article_sha = digest(&article);
    if manifest["schema_version"] != 1
        || manifest["size"].as_u64() != Some(size as u64)
        || manifest["density"] != density
        || manifest["data_bytes"].as_u64() != Some(size as u64)
        || data.len() != size
        || manifest["article_bytes"].as_u64() != Some(article.len() as u64)
        || manifest["data_sha256"] != data_sha
        || manifest["article_sha256"] != article_sha
    {
        return Err("prepared fixture differs from its manifest".into());
    }
    let name = format!("{size}-{density}");
    let data_path = output.join(format!("{name}.data"));
    let article_path = output.join(format!("{name}.article"));
    frozen_file(&data_path, &data)?;
    frozen_file(&article_path, &article)?;
    frozen_file(&output.join(format!("{name}.manifest.json")), &raw)?;
    Ok(Fixture {
        name,
        data_path,
        article_path,
        preparation_manifest_sha256: digest(&raw),
        data_sha256: data_sha,
        article_sha256: article_sha,
        decoded_bytes: size as u64,
        crc32: crc32fast::hash(&data),
        crc_seed: crc32fast::hash(&data[..size / 2]),
    })
}

fn hash_text(value: &Value, length: usize) -> bool {
    value
        .as_str()
        .is_some_and(|s| s.len() == length && s.bytes().all(|b| b.is_ascii_hexdigit()))
}

fn experimental_build(argv: &[Value]) -> bool {
    let Some(args) = argv.iter().map(Value::as_str).collect::<Option<Vec<_>>>() else {
        return false;
    };
    if args.is_empty() || Path::new(args[0]).file_name().and_then(|v| v.to_str()) != Some("cargo") {
        return false;
    }
    let mut i = 1;
    if args
        .get(i)
        .is_some_and(|v| v.starts_with('+') && v.len() > 1)
    {
        i += 1;
    }
    if args.get(i) != Some(&"build") {
        return false;
    }
    i += 1;
    let mut release = false;
    let mut feature = false;
    while i < args.len() {
        let arg = args[i];
        i += 1;
        if arg == "--release" {
            release = true;
            continue;
        }
        if [
            "--locked",
            "--no-default-features",
            "--lib",
            "--bins",
            "--quiet",
        ]
        .contains(&arg)
        {
            continue;
        }
        let (key, value) = if let Some(pair) = arg.split_once('=') {
            pair
        } else {
            if ![
                "--features",
                "--manifest-path",
                "--target-dir",
                "--target",
                "--jobs",
                "--bin",
                "--package",
                "-j",
                "-p",
            ]
            .contains(&arg)
            {
                return false;
            }
            let Some(value) = args.get(i) else {
                return false;
            };
            i += 1;
            (arg, *value)
        };
        if value.is_empty() || value.starts_with("--") {
            return false;
        }
        if key == "--features" {
            feature |= value
                .split(|c: char| c == ',' || c.is_ascii_whitespace())
                .any(|v| v == "experimental-payload-kernels");
        } else if ![
            "--manifest-path",
            "--target-dir",
            "--target",
            "--jobs",
            "--bin",
            "--package",
            "-j",
            "-p",
        ]
        .contains(&key)
        {
            return false;
        }
    }
    release && feature
}

fn admit(left: &Value, right: &Value, library_sha: &str, worker_sha: &str) -> Result<(), String> {
    let fail = || Err("missing, conflicting or invalid Rust A/A build/source identity".into());
    if left != right
        || left["comparison_kind"] != "rust-aa-control"
        || left["rust_payload_mode"] != "experimental"
        || left["library_sha256"] != library_sha
        || left["worker_sha256"] != worker_sha
        || !(hash_text(&left["git_head"], 40) || hash_text(&left["git_head"], 64))
    {
        return fail();
    }
    for key in [
        "library_sha256",
        "worker_sha256",
        "cargo_lock_sha256",
        "cargo_toml_sha256",
        "fixture_generator_sha256",
        "fixture_encoder_sha256",
    ] {
        if !hash_text(&left[key], 64) {
            return fail();
        }
    }
    let Some(sources) = left["rust_source_sha256"].as_object() else {
        return fail();
    };
    if sources.is_empty()
        || sources.len() > 512
        || sources.iter().any(|(path, hash)| {
            !path.starts_with("src/")
                || !path.ends_with(".rs")
                || path.split('/').any(|part| part == ".." || part.is_empty())
                || !hash_text(hash, 64)
        })
    {
        return fail();
    }
    for key in ["rustc", "cargo"] {
        if !left[key]
            .as_str()
            .is_some_and(|s| !s.is_empty() && s.len() <= 4096)
        {
            return fail();
        }
    }
    let Some(argv) = left["build_argv"].as_array() else {
        return fail();
    };
    if argv.is_empty()
        || argv.len() > 128
        || !experimental_build(argv)
        || !left["build_env"].is_object()
        || left["runner_identity"]["os"] != "linux"
        || left["runner_identity"]["arch"] != "aarch64"
    {
        return fail();
    }
    Ok(())
}

struct Expected<'a> {
    data_sha: &'a str,
    article_sha: &'a str,
    library_sha: &'a str,
    worker_sha: &'a str,
    decoded_bytes: u64,
    crc32: u32,
    crc_seed: u32,
    operation: &'a str,
    iterations: u64,
}

fn exact_probe(probe: &Value, expected: &Expected<'_>) -> bool {
    probe["exact_output"] == true
        && probe["output_sha256"] == expected.data_sha
        && probe["prefix_capacity_canary_intact"] == true
        && probe["suffix_capacity_canary_intact"] == true
        && probe["unused_capacity_changed_bytes"].as_u64() == Some(0)
}

fn eligible(record: &Value, expected: &Expected<'_>) -> bool {
    let result = &record["result"];
    if record["status"] != "ok"
        || record["exit_code"].as_i64() != Some(0)
        || record["stderr_bytes"].as_u64() != Some(0)
        || result["status"] != "ok"
        || result["backend"] != "rust"
        || result["operation"] != expected.operation
        || result["iterations"].as_u64() != Some(expected.iterations)
        || result["data_sha256"] != expected.data_sha
        || result["article_sha256"] != expected.article_sha
        || result["script_sha256"] != expected.worker_sha
        || result["library"]["sha256"] != expected.library_sha
        || result["expected_crc32"] != format!("{:08x}", expected.crc32)
        || result["continuation_seed_crc32"] != format!("{:08x}", expected.crc_seed)
        || result["zero_length_crc32"] != format!("{:08x}", expected.crc_seed)
        || !result["per_call_ns"]
            .as_f64()
            .is_some_and(|v| v.is_finite() && v > 0.0)
    {
        return false;
    }
    let Some(batch_ns) = result["batch_elapsed_ns"].as_u64().filter(|v| *v > 0) else {
        return false;
    };
    if expected.iterations == 0 {
        return false;
    }
    let derived_ns = batch_ns as f64 / expected.iterations as f64;
    let reported_ns = result["per_call_ns"].as_f64().unwrap();
    // Python emits a rounded decimal and serde_json may round it again while
    // parsing. The redundant float is only a crosscheck; integer batch time is
    // authoritative. Four relative machine epsilons cover representational
    // rounding while rejecting materially inconsistent durations.
    if (reported_ns - derived_ns).abs() > derived_ns * (4.0 * f64::EPSILON) {
        return false;
    }
    let decoded = expected.operation.starts_with("decode");
    let answer = if decoded {
        expected.decoded_bytes
    } else {
        u64::from(expected.crc32)
    };
    if result["last_result"].as_u64() != Some(answer)
        || !result["timed_call_results"]
            .as_array()
            .is_some_and(|values| {
                values.len() as u64 == expected.iterations
                    && values.iter().all(|v| v.as_u64() == Some(answer))
            })
    {
        return false;
    }
    if decoded {
        exact_probe(&result["warm_output_probe"], expected)
            && exact_probe(&result["output_probe"], expected)
    } else {
        result["warm_output_probe"].is_null() && result["output_probe"].is_null()
    }
}

fn pair_record(a: &Value, b: &Value, expected: &Expected<'_>) -> Value {
    let valid = eligible(a, expected) && eligible(b, expected);
    let ratio = if valid {
        Some(
            // Both observations passed the identical iteration-count gate, so
            // it cancels. Never use the redundant JSON float in the ratio.
            a["result"]["batch_elapsed_ns"].as_u64().unwrap() as f64
                / b["result"]["batch_elapsed_ns"].as_u64().unwrap() as f64,
        )
    } else {
        None
    };
    let ratio = ratio.filter(|v| v.is_finite() && *v > 0.0);
    let valid = valid && ratio.is_some();
    json!({"type":"pair", "comparison_kind":"rust-aa-control", "eligible":valid,
        "latency_ratio_control_a_over_b":ratio,
        "basis":"paired native calls including Python ctypes loop; no C++ or actual PHP caller comparison"})
}

#[derive(Debug)]
struct Options {
    paths: std::collections::BTreeMap<String, String>,
    samples: u64,
    iterations: u64,
    seed: u64,
    timeout: u64,
}
const PATH_KEYS: [&str; 10] = [
    "fixtures",
    "library-a",
    "library-b",
    "manifest-a",
    "manifest-b",
    "worker",
    "python",
    "cpu-snapshot",
    "output",
    "source-root",
];
fn options(args: &[String]) -> Result<Options, String> {
    let mut values = std::collections::BTreeMap::new();
    for arg in args {
        let (key, value) = arg
            .strip_prefix("--")
            .and_then(|v| v.split_once('='))
            .ok_or("expected --name=value arguments")?;
        if (!PATH_KEYS.contains(&key)
            && !["samples", "iterations", "seed", "timeout"].contains(&key))
            || value.is_empty()
            || values.insert(key.to_string(), value.to_string()).is_some()
        {
            return Err("unknown, empty or duplicate argument".into());
        }
    }
    let mut paths = std::collections::BTreeMap::new();
    for key in PATH_KEYS {
        let path = values
            .remove(key)
            .ok_or_else(|| format!("missing --{key}"))?;
        if !Path::new(&path).is_absolute() {
            return Err("paths must be absolute".into());
        }
        paths.insert(key.to_string(), path);
    }
    let number = |key: &str, default: u64, cap: u64| -> Result<u64, String> {
        let value = match values.get(key) {
            None => default,
            Some(raw) => {
                if !raw.bytes().all(|b| b.is_ascii_digit()) {
                    return Err(format!("invalid {key}"));
                }
                raw.parse().map_err(|_| format!("invalid {key}"))?
            }
        };
        if value == 0 || value > cap {
            Err(format!("out of range {key}"))
        } else {
            Ok(value)
        }
    };
    Ok(Options {
        paths,
        samples: number("samples", 31, 101)?,
        iterations: number("iterations", 8, 1000)?,
        seed: number("seed", 20261015, u64::MAX)?,
        timeout: number("timeout", 30, 300)?,
    })
}

#[derive(Default)]
struct SourceBudget {
    entries: usize,
    bytes: usize,
}

fn rust_inventory(
    directory: &Path,
    prefix: &str,
    depth: usize,
    found: &mut std::collections::BTreeMap<String, Vec<u8>>,
    budget: &mut SourceBudget,
) -> Result<(), String> {
    if depth > 8 || found.len() > 512 {
        return Err("source inventory exceeds budget".into());
    }
    let metadata = fs::symlink_metadata(directory).map_err(|e| e.to_string())?;
    if !metadata.is_dir() || metadata.file_type().is_symlink() {
        return Err("source directory must not be a symlink".into());
    }
    let mut entries = 0;
    for entry in fs::read_dir(directory).map_err(|e| e.to_string())? {
        if budget.entries >= 2048 {
            return Err("global source entry budget exceeded".into());
        }
        budget.entries += 1;
        entries += 1;
        if entries > 1024 {
            return Err("source directory entry budget exceeded".into());
        }
        let entry = entry.map_err(|e| e.to_string())?;
        let name = entry
            .file_name()
            .into_string()
            .map_err(|_| "non UTF-8 source filename")?;
        let key = format!("{prefix}/{name}");
        let kind = entry.file_type().map_err(|e| e.to_string())?;
        if kind.is_symlink() {
            return Err("source inventory contains symlink".into());
        }
        if kind.is_dir() {
            rust_inventory(&entry.path(), &key, depth + 1, found, budget)?;
        } else if name.ends_with(".rs") {
            if found.len() >= 512 {
                return Err("source inventory exceeds budget".into());
            }
            let bytes = read_regular(&entry.path(), 2_097_152)?;
            budget.bytes = budget
                .bytes
                .checked_add(bytes.len())
                .filter(|v| *v <= 33_554_432)
                .ok_or("global source byte budget exceeded")?;
            found.insert(key, bytes);
        }
    }
    Ok(())
}

fn freeze_sources(source: &Path, output: &Path, manifest: &Value) -> Result<Value, String> {
    let crate_root = source.join("docker/app/nzb-yenc-ffi");
    let mut found = std::collections::BTreeMap::new();
    rust_inventory(
        &crate_root.join("src"),
        "src",
        0,
        &mut found,
        &mut SourceBudget::default(),
    )?;
    let expected = manifest["rust_source_sha256"]
        .as_object()
        .ok_or("source inventory missing")?;
    if found.len() != expected.len()
        || found
            .iter()
            .any(|(path, bytes)| expected.get(path) != Some(&json!(digest(bytes))))
    {
        return Err("complete source inventory changed".into());
    }
    for (name, path, key) in [
        (
            "Cargo.lock",
            crate_root.join("Cargo.lock"),
            "cargo_lock_sha256",
        ),
        (
            "Cargo.toml",
            crate_root.join("Cargo.toml"),
            "cargo_toml_sha256",
        ),
        (
            "fixture-generator.php",
            source.join("scripts/profile-yenc.php"),
            "fixture_generator_sha256",
        ),
        (
            "fixture-encoder.php",
            source.join("tests/Fixtures/YencArticles.php"),
            "fixture_encoder_sha256",
        ),
        (
            "abi-worker.py",
            source.join("scripts/benchmark-yenc-abi.py"),
            "worker_sha256",
        ),
    ] {
        let bytes = read_regular(&path, 2_097_152)?;
        if manifest[key] != digest(&bytes) {
            return Err(format!("{key} changed"));
        }
        found.insert(name.to_string(), bytes);
    }
    let mut receipts = Vec::new();
    for (index, (path, bytes)) in found.into_iter().enumerate() {
        let frozen_path = output.join(format!("source-{index}.bin"));
        frozen_file(&frozen_path, &bytes)?;
        receipts.push(json!({"source_path":path,"frozen_path":frozen_path,"sha256":digest(&bytes),"bytes":bytes.len()}));
    }
    Ok(json!(receipts))
}

#[derive(serde::Serialize)]
struct Step {
    fixture_index: usize,
    operation: String,
    sample: u64,
    order: [&'static str; 2],
}
struct ScheduleContext<'a> {
    fixtures: &'a [Fixture],
    library_sha: &'a str,
    worker_sha: &'a str,
    iterations: u64,
}
fn emit(out: &mut fs::File, record: &Value) -> Result<(), String> {
    serde_json::to_writer(&mut *out, record).map_err(|e| e.to_string())?;
    out.write_all(b"\n").map_err(|e| e.to_string())?;
    out.flush().map_err(|e| e.to_string())
}

fn run_schedule<F>(
    steps: &[Step],
    context: &ScheduleContext<'_>,
    out: &mut fs::File,
    mut runner: F,
) -> Result<Value, String>
where
    F: FnMut(&Fixture, &str, u64, &str) -> Result<Value, String>,
{
    let mut eligible_pairs = 0;
    let mut invalid_pairs = 0;
    let mut groups = std::collections::BTreeMap::<(String, String), (Vec<f64>, usize)>::new();
    for step in steps {
        let fixture = context
            .fixtures
            .get(step.fixture_index)
            .ok_or("schedule fixture index out of range")?;
        if ![
            "decode-lined",
            "decode-flat",
            "crc-whole",
            "crc-continuation",
        ]
        .contains(&step.operation.as_str())
            || !matches!(
                step.order,
                ["control_a", "control_b"] | ["control_b", "control_a"]
            )
        {
            return Err("invalid frozen schedule operation or roles".into());
        }
        let expected = Expected {
            data_sha: &fixture.data_sha256,
            article_sha: &fixture.article_sha256,
            library_sha: context.library_sha,
            worker_sha: context.worker_sha,
            decoded_bytes: fixture.decoded_bytes,
            crc32: fixture.crc32,
            crc_seed: fixture.crc_seed,
            operation: &step.operation,
            iterations: context.iterations,
        };
        let mut members = std::collections::BTreeMap::new();
        for (position, slot) in step.order.iter().enumerate() {
            let record = runner(fixture, &step.operation, step.sample, slot).unwrap_or_else(
                |error| json!({"status":"executor_error","error":error,"result":null}),
            );
            emit(
                out,
                &json!({"type":"observation","comparison_kind":"rust-aa-control","fixture":fixture.name,
                "operation":step.operation,"sample":step.sample,"position":position,"order":step.order,
                "slot":slot,"backend":"rust","receipt":record}),
            )?;
            members.insert(*slot, record);
        }
        let mut pair = pair_record(&members["control_a"], &members["control_b"], &expected);
        pair["fixture"] = json!(fixture.name);
        pair["operation"] = json!(step.operation);
        pair["sample"] = json!(step.sample);
        pair["order"] = json!(step.order);
        pair["provisional_until_final_identity_check"] = json!(true);
        let group = groups
            .entry((fixture.name.clone(), step.operation.clone()))
            .or_default();
        if pair["eligible"] == true {
            eligible_pairs += 1;
            group.0.push(
                pair["latency_ratio_control_a_over_b"]
                    .as_f64()
                    .ok_or("invalid ratio")?,
            );
        } else {
            invalid_pairs += 1;
            group.1 += 1;
        }
        emit(out, &pair)?;
    }
    let summaries:Vec<_>=groups.into_iter().map(|((fixture,operation),(mut ratios,invalid))|{
        ratios.sort_by(f64::total_cmp);let n=ratios.len();
        let median=if n==0{None}else if n%2==0{Some(ratios[n/2-1]+(ratios[n/2]-ratios[n/2-1])/2.0)}else{Some(ratios[n/2])};
        json!({"fixture":fixture,"operation":operation,"eligible_pairs":n,"invalid_pairs":invalid,
            "median_paired_latency_ratio_control_a_over_b":if invalid==0{median}else{None},
            "paired_ratios":ratios,"noise_equivalence_qualified":false,
            "basis":"descriptive paired control observations only; no formal noise-band or adoption verdict"})
    }).collect();
    Ok(json!({"eligible_pairs":eligible_pairs,"invalid_pairs":invalid_pairs,"summaries":summaries}))
}

fn kernel_verified(record: &Value, sha: &str) -> bool {
    record["status"] == "ok"
        && record["exit_code"].as_i64() == Some(0)
        && record["stderr_bytes"].as_u64() == Some(0)
        && record["result"]["kernel"] == 4
        && record["result"]["library_sha256"] == sha
}
fn run_child_raw(command: &mut Command, prefix: &Path, timeout: Duration) -> Result<Value, String> {
    run_child_mode(command, prefix, timeout, false)
}

const PROBE: &str = r#"import ctypes,hashlib,json,sys
p=sys.argv[1]
l=ctypes.CDLL(p)
f=l.nntmux_yenc_payload_kernel_v1
f.argtypes=[]
f.restype=ctypes.c_uint32
print(json.dumps({'kernel':int(f()),'library_sha256':hashlib.sha256(open(p,'rb').read()).hexdigest()}))
"#;

fn cpu_endpoint(record: &Value, label: &str) -> Option<(u64, Vec<u64>)> {
    if record["label"] != label
        || record["receipt"]["status"] != "ok"
        || record["receipt"]["exit_code"].as_i64() != Some(0)
        || record["receipt"]["stderr_bytes"].as_u64() != Some(0)
        || record["endpoint"]["schema_version"] != 1
        || record["endpoint"]["os_cpu_usable"] != true
        || record["endpoint"]["os"]["stat"]["status"] != "captured"
    {
        return None;
    }
    let stamp = record["endpoint"]["monotonic_ns"]
        .as_u64()
        .filter(|v| *v > 0)?;
    let raw = record["endpoint"]["os"]["stat"]["raw"].as_str()?;
    let line = raw.lines().find(|line| line.starts_with("cpu "))?;
    let ticks = line
        .split_ascii_whitespace()
        .skip(1)
        .map(|v| {
            if v.is_empty() || !v.bytes().all(|b| b.is_ascii_digit()) {
                None
            } else {
                v.parse::<u64>().ok()
            }
        })
        .collect::<Option<Vec<_>>>()?;
    if ticks.len() < 4 {
        return None;
    }
    Some((stamp, ticks))
}

fn final_control_status(
    intact: bool,
    invalid_pairs: u64,
    before: &Value,
    after: &Value,
) -> &'static str {
    if !intact {
        return "invalidated_identity_drift";
    }
    if invalid_pairs != 0 {
        return "partial_control";
    }
    let qualified = cpu_endpoint(before, "before")
        .zip(cpu_endpoint(after, "after"))
        .is_some_and(|((start, a), (end, b))| {
            end > start && a.len() == b.len() && a.iter().zip(&b).all(|(x, y)| y >= x)
        });
    if qualified {
        "control_observations_complete"
    } else {
        "partial_control_cpu_interval_unavailable"
    }
}

fn freeze_bound_identity(
    path: &Path,
    bytes: &[u8],
    cap: usize,
    identities: &mut Vec<(std::path::PathBuf, String, usize)>,
) -> Result<(), String> {
    if bytes.len() > cap {
        return Err("frozen identity exceeds its validation budget".into());
    }
    frozen_file(path, bytes)?;
    identities.push((path.to_path_buf(), digest(bytes), cap));
    Ok(())
}

fn identity_receipts(identities: &[(std::path::PathBuf, String, usize)]) -> Value {
    json!(identities.iter().map(|(path,expected,cap)|{
        match read_regular(path,*cap){
            Ok(bytes)=>json!({"path":path,"expected_sha256":expected,"actual_sha256":digest(&bytes),"matches":digest(&bytes)==*expected}),
            Err(error)=>json!({"path":path,"expected_sha256":expected,"matches":false,"error":error})
        }
    }).collect::<Vec<_>>())
}

fn execute(options: &Options) -> Result<Value, String> {
    if std::env::consts::OS != "linux" || std::env::consts::ARCH != "aarch64" {
        return Err(
            "this control requires disposable Linux ARM64; no control measurements started".into(),
        );
    }
    let requested = Path::new(&options.paths["output"]);
    let output = requested
        .parent()
        .ok_or("output parent missing")?
        .canonicalize()
        .map_err(|e| e.to_string())?
        .join(requested.file_name().ok_or("output name missing")?);
    let source = Path::new(&options.paths["source-root"])
        .canonicalize()
        .map_err(|e| e.to_string())?;
    let input_fixtures = Path::new(&options.paths["fixtures"])
        .canonicalize()
        .map_err(|e| e.to_string())?;
    if output.starts_with(&source)
        || output.starts_with(&input_fixtures)
        || source.starts_with(&output)
        || input_fixtures.starts_with(&output)
    {
        return Err("evidence, source and fixture directories must be separate".into());
    }
    fs::DirBuilder::new()
        .mode(0o700)
        .create(&output)
        .map_err(|e| e.to_string())?;
    let mut out = exclusive(&output.join("observations.jsonl"))?;
    let result = execute_owned(options, &source, &input_fixtures, &output, &mut out);
    if let Err(error) = &result {
        emit(
            &mut out,
            &json!({"type":"run_failure","comparison_kind":"rust-aa-control","status":"invalid_control","error":error}),
        )?;
    }
    result
}

fn execute_owned(
    options: &Options,
    source: &Path,
    input_fixtures: &Path,
    output: &Path,
    out: &mut fs::File,
) -> Result<Value, String> {
    let timeout = Duration::from_secs(options.timeout);
    let mut identities = Vec::new();
    let a = read_regular(Path::new(&options.paths["library-a"]), 67_108_864)?;
    let b = read_regular(Path::new(&options.paths["library-b"]), 67_108_864)?;
    let library_sha = digest(&a);
    if digest(&b) != library_sha {
        return Err("different library bytes are A/B, not an A/A control".into());
    }
    let worker = read_regular(Path::new(&options.paths["worker"]), 262_144)?;
    let worker_sha = digest(&worker);
    let manifest_a = read_regular(Path::new(&options.paths["manifest-a"]), 1_048_576)?;
    let manifest_b = read_regular(Path::new(&options.paths["manifest-b"]), 1_048_576)?;
    if manifest_a != manifest_b {
        return Err("A/A source/build manifest bytes differ".into());
    }
    let manifest: Value = serde_json::from_slice(&manifest_a).map_err(|e| e.to_string())?;
    admit(&manifest, &manifest, &library_sha, &worker_sha)?;
    freeze_bound_identity(
        &output.join("runtime-a.json"),
        &manifest_a,
        1_048_576,
        &mut identities,
    )?;
    freeze_bound_identity(
        &output.join("runtime-b.json"),
        &manifest_b,
        1_048_576,
        &mut identities,
    )?;
    let mut libraries = std::collections::BTreeMap::new();
    for (slot, bytes) in [("control_a", &a), ("control_b", &b)] {
        let path = output.join(format!("{slot}.so"));
        frozen_file(&path, bytes)?;
        identities.push((path.clone(), library_sha.clone(), 67_108_864));
        libraries.insert(slot, path);
    }
    let worker_path = output.join("benchmark-yenc-abi.py");
    frozen_file(&worker_path, &worker)?;
    identities.push((worker_path.clone(), worker_sha.clone(), 262_144));
    let source_snapshot = freeze_sources(source, output, &manifest)?;
    for row in source_snapshot.as_array().ok_or("source snapshot shape")? {
        identities.push((
            row["frozen_path"]
                .as_str()
                .ok_or("source snapshot path")?
                .into(),
            row["sha256"]
                .as_str()
                .ok_or("source snapshot hash")?
                .to_string(),
            2_097_152,
        ));
    }
    let mut runtimes = Vec::new();
    let mut runtime_paths = std::collections::BTreeMap::new();
    for (role, path) in [
        ("python", std::path::PathBuf::from(&options.paths["python"])),
        (
            "cpu-snapshot",
            std::path::PathBuf::from(&options.paths["cpu-snapshot"]),
        ),
        (
            "orchestrator",
            std::env::current_exe().map_err(|e| e.to_string())?,
        ),
    ] {
        let path = path.canonicalize().map_err(|e| e.to_string())?;
        let bytes = read_regular(&path, 67_108_864)?;
        let hash = digest(&bytes);
        let frozen = output.join(format!("runtime-{role}.bin"));
        frozen_file(&frozen, &bytes)?;
        identities.push((path.clone(), hash.clone(), 67_108_864));
        identities.push((frozen.clone(), hash.clone(), 67_108_864));
        runtimes.push(
            json!({"role":role,"executed_path":path,"retained_bytes_path":frozen,"sha256":hash,
            "scope":"executable identity; not a complete dynamic runtime or host image snapshot"}),
        );
        runtime_paths.insert(role, path);
    }
    let probe = output.join("kernel-probe.py");
    frozen_file(&probe, PROBE.as_bytes())?;
    identities.push((probe.clone(), digest(PROBE.as_bytes()), 262_144));
    let mut probes = Vec::new();
    for (slot, path) in &libraries {
        let record = run_child(
            Command::new(&runtime_paths["python"])
                .arg(&probe)
                .arg(path)
                .current_dir(output),
            &output.join(format!("probe-{slot}")),
            timeout,
        )?;
        let valid = kernel_verified(&record, &library_sha);
        emit(
            out,
            &json!({"type":"untimed_kernel_probe","slot":slot,"backend":"rust","verified":valid,"receipt":record}),
        )?;
        if !valid {
            return Err("admitted library did not report NEON4 in bounded untimed probe".into());
        }
        probes.push(record);
    }
    let fixtures_out = output.join("fixtures");
    fs::DirBuilder::new()
        .mode(0o700)
        .create(&fixtures_out)
        .map_err(|e| e.to_string())?;
    let mut fixtures = Vec::new();
    for size in [4096, 524_288, 1_048_576, 8_388_608] {
        for density in ["ordinary", "heavy"] {
            let fixture = freeze_fixture(
                &input_fixtures.join(format!("{size}-{density}")),
                &fixtures_out,
                size,
                density,
            )?;
            identities.push((fixture.data_path.clone(), fixture.data_sha256.clone(), size));
            identities.push((
                fixture.article_path.clone(),
                fixture.article_sha256.clone(),
                size * 2 + size.div_ceil(64) * 2 + 256,
            ));
            identities.push((
                fixtures_out.join(format!("{}.manifest.json", fixture.name)),
                fixture.preparation_manifest_sha256.clone(),
                4096,
            ));
            fixtures.push(fixture);
        }
    }
    let mut steps = Vec::new();
    for (fixture_index, fixture) in fixtures.iter().enumerate() {
        for operation in [
            "decode-lined",
            "decode-flat",
            "crc-whole",
            "crc-continuation",
        ] {
            for sample in 0..options.samples {
                steps.push(Step {
                    fixture_index,
                    operation: operation.into(),
                    sample,
                    order: control_order(options.seed, &fixture.name, operation, sample),
                });
            }
        }
    }
    let schedule = serde_json::to_vec(&steps).map_err(|e| e.to_string())?;
    let schedule_path = output.join("schedule.json");
    frozen_file(&schedule_path, &schedule)?;
    identities.push((schedule_path, digest(&schedule), 2_097_152));
    let mut protocol = json!({"type":"protocol","version":"rust-aa-control-v1","comparison_kind":"rust-aa-control",
        "claim":"A/A ABI control observations only; no C++ comparison, actual PHP caller result, speed qualification or adoption verdict",
        "host":{"os":std::env::consts::OS,"arch":std::env::consts::ARCH},"source_build_manifest":manifest,
        "source_snapshot":source_snapshot,"runtime_executables":runtimes,"kernel_probes":probes,
        "libraries":libraries,"library_sha256":library_sha,"worker_sha256":worker_sha,
        "fixture_inventory":fixtures,"schedule_sha256":digest(&schedule),"scheduled_pairs":steps.len(),
        "samples_per_fixture_operation":options.samples,"iterations_per_worker_batch":options.iterations,
        "seed":options.seed,"timeout_seconds":options.timeout,"warmup_calls_per_worker":1,
        "crc_reference":"existing Python zlib oracle inside each worker, plus frozen independent Rust CRC known answers; no separately timed zlib slot",
        "boundary":"worker batch of native calls with resident preallocated buffers, including Python ctypes loop; setup, warmup, hashing and verification untimed",
        "counter_scope":"dedicated snapshots bracket control scheduling and subprocess overhead; worker getrusage separately brackets its timed batch",
        "cleanup_scope":"direct worker only; owned frozen worker has no subprocess spawning path",
        "per_child_retained_file_limit_bytes":RETAINED_OUTPUT_CAP,
        "resource_limit_scope":"each child has RLIMIT_FSIZE and disabled core dumps; parent limits unchanged; includes child-written CPU endpoint files",
        "library_pairing":"both roles use the same library bytes and backend rust; each pair requires both observations",
        "fixture_label_basis":"ordinary/heavy labels and expected sizes are declarations from bound preparation manifests; bytes are frozen and independently checked by the worker",
        "compiled_mode_basis":"validated declared Cargo argv and bound source/build manifest; the runtime kernel export identifies NEON but does not independently identify compiled feature mode",
        "noise_equivalence_qualified":false,"adoption_qualified":false});
    protocol["protocol_sha256"] = json!(digest(
        &serde_json::to_vec(&protocol).map_err(|e| e.to_string())?
    ));
    emit(out, &protocol)?;
    let before = identity_receipts(&identities);
    emit(out, &json!({"type":"identity_before","receipts":before}))?;
    if !before
        .as_array()
        .ok_or("identity shape")?
        .iter()
        .all(|r| r["matches"] == true)
    {
        return Err("frozen identity changed before measurement".into());
    }
    let counter = |label: &str| -> Value {
        let path = output.join(format!("cpu-{label}.json"));
        let receipt = run_child_raw(
            Command::new(&runtime_paths["cpu-snapshot"])
                .arg("--output")
                .arg(&path)
                .current_dir(output),
            &output.join(format!("cpu-{label}-process")),
            timeout,
        )
        .unwrap_or_else(|error| json!({"status":"executor_error","error":error}));
        let endpoint = read_regular(&path, 1_048_576)
            .ok()
            .and_then(|v| serde_json::from_slice::<Value>(&v).ok());
        json!({"type":"cpu_endpoint","label":label,"receipt":receipt,"endpoint":endpoint,"missing_counters_are_unavailable":true})
    };
    let cpu_before = counter("before");
    emit(out, &cpu_before)?;
    let context = ScheduleContext {
        fixtures: &fixtures,
        library_sha: &library_sha,
        worker_sha: &worker_sha,
        iterations: options.iterations,
    };
    let measured = run_schedule(&steps, &context, out, |fixture, operation, sample, slot| {
        run_child(
            Command::new(&runtime_paths["python"])
                .arg(&worker_path)
                .args([
                    "--backend",
                    "rust",
                    "--operation",
                    operation,
                    "--iterations",
                    &options.iterations.to_string(),
                ])
                .arg("--data")
                .arg(&fixture.data_path)
                .arg("--article")
                .arg(&fixture.article_path)
                .arg("--library")
                .arg(&libraries[slot])
                .current_dir(output),
            &output.join(format!("{}-{operation}-{sample}-{slot}", fixture.name)),
            timeout,
        )
    });
    // Always attempt the ending endpoint after a scheduling/executor failure.
    let cpu_after = counter("after");
    emit(out, &cpu_after)?;
    let after = identity_receipts(&identities);
    let intact = after
        .as_array()
        .ok_or("identity shape")?
        .iter()
        .all(|r| r["matches"] == true);
    emit(
        out,
        &json!({"type":"identity_after","receipts":after,"all_identities_unchanged":intact}),
    )?;
    let mut summary = measured?;
    let status = final_control_status(
        intact,
        summary["invalid_pairs"]
            .as_u64()
            .ok_or("invalid pair count")?,
        &cpu_before,
        &cpu_after,
    );
    let complete = status == "control_observations_complete";
    summary["type"] = json!("run_summary");
    summary["comparison_kind"] = json!("rust-aa-control");
    summary["status"] = json!(status);
    summary["cpu_interval_qualified"] = json!(
        final_control_status(true, 0, &cpu_before, &cpu_after) == "control_observations_complete"
    );
    summary["all_identities_unchanged"] = json!(intact);
    summary["noise_equivalence_qualified"] = json!(false);
    summary["adoption_qualified"] = json!(false);
    if !intact {
        summary["summaries"] = Value::Null;
        summary["qualified_pairs"] = json!(0);
    }
    emit(out, &summary)?;
    if complete {
        Ok(summary)
    } else {
        Err("invalid or incomplete control; all receipts retained".into())
    }
}

fn main() {
    let args: Vec<_> = std::env::args().skip(1).collect();
    if args == ["--help"] {
        println!(
            "Rust A/A ABI control, disposable Linux ARM64 only. Every path must be absolute.\nRequired --name=value paths: {}\nOptional: --samples=31 --iterations=8 --seed=20261015 --timeout=30\nRequires identical library and complete source/build manifest bytes. No C++/PHP caller/adoption verdict.",
            PATH_KEYS.join(", ")
        );
        return;
    }
    match options(&args).and_then(|options| execute(&options)) {
        Ok(summary) => println!("{summary}"),
        Err(error) => {
            eprintln!("{}", json!({"status":"invalid_control","error":error}));
            std::process::exit(1);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct Scratch(std::path::PathBuf);
    impl Scratch {
        fn new() -> Self {
            use std::sync::atomic::{AtomicU64, Ordering};
            static ID: AtomicU64 = AtomicU64::new(0);
            let path = std::env::temp_dir().join(format!(
                "nntmux-aa-test-{}-{}-{}",
                std::process::id(),
                std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap()
                    .as_nanos(),
                ID.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&path).unwrap();
            Self(path)
        }
    }
    impl Drop for Scratch {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.0).unwrap();
        }
    }

    #[test]
    fn bounded_reads_reject_symlinks_directories_and_oversize_without_truncating() {
        let scratch = Scratch::new();
        let path = scratch.0.join("input");
        fs::write(&path, b"abc").unwrap();
        assert_eq!(read_regular(&path, 3).unwrap(), b"abc");
        assert!(read_regular(&path, 2).is_err());
        assert_eq!(fs::read(&path).unwrap(), b"abc");
        assert!(read_regular(&scratch.0, 3).is_err());
        let link = scratch.0.join("link");
        std::os::unix::fs::symlink(&path, &link).unwrap();
        assert!(read_regular(&link, 3).is_err());
    }

    #[test]
    fn seeded_control_schedule_is_reproducible_and_can_place_either_slot_first() {
        let a: Vec<_> = (0..64)
            .map(|n| control_order(1, "4096-heavy", "decode-flat", n))
            .collect();
        assert_eq!(
            a,
            (0..64)
                .map(|n| control_order(1, "4096-heavy", "decode-flat", n))
                .collect::<Vec<_>>()
        );
        assert_ne!(
            a,
            (0..64)
                .map(|n| control_order(2, "4096-heavy", "decode-flat", n))
                .collect::<Vec<_>>()
        );
        assert!(a.iter().any(|v| v[0] == "control_b"));
        assert!(a.iter().any(|v| v[0] == "control_a"));
    }

    #[test]
    fn child_receipts_retain_exit_stderr_overflow_timeout_and_exclusive_paths() {
        let scratch = Scratch::new();
        let prefix = scratch.0.join("good");
        let good = run_child(
            Command::new("/bin/sh").args(["-c", "printf '{\"status\":\"ok\"}'"]),
            &prefix,
            Duration::from_secs(2),
        )
        .unwrap();
        assert_eq!(good["status"], "ok");
        assert_eq!(good["result"]["status"], "ok");
        assert!(
            run_child(
                Command::new("/bin/sh").arg("-c").arg("exit 0"),
                &prefix,
                Duration::from_secs(2)
            )
            .is_err()
        );
        let failed = run_child(
            Command::new("/bin/sh").args(["-c", "printf failed >&2; exit 7"]),
            &scratch.0.join("failed"),
            Duration::from_secs(2),
        )
        .unwrap();
        assert_eq!(failed["status"], "child_failed");
        assert_eq!(failed["exit_code"], 7);
        assert_eq!(failed["stderr_bytes"], 6);
        let overflow = run_child(
            Command::new("/bin/sh").args(["-c", "head -c 300000 /dev/zero"]),
            &scratch.0.join("overflow"),
            Duration::from_secs(2),
        )
        .unwrap();
        assert_eq!(overflow["status"], "output_limit_reached");
        assert!(overflow["result"].is_null());
        let timed = run_child(
            Command::new("/bin/sh").args(["-c", "exec sleep 10"]),
            &scratch.0.join("timeout"),
            Duration::from_millis(20),
        )
        .unwrap();
        assert_eq!(timed["status"], "timeout");
        assert_eq!(timed["direct_child_reaped"], true);
        let absent = run_child(
            &mut Command::new(scratch.0.join("missing")),
            &scratch.0.join("missing"),
            Duration::from_secs(1),
        )
        .unwrap();
        assert_eq!(absent["status"], "spawn_failed");
    }

    #[test]
    fn prepared_fixtures_are_bound_to_manifest_bytes_and_frozen_without_overwrite() {
        let scratch = Scratch::new();
        let source = scratch.0.join("source");
        let output = scratch.0.join("output");
        fs::create_dir(&source).unwrap();
        fs::create_dir(&output).unwrap();
        let data = b"ABC";
        let article =
            b"=ybegin line=128 size=3 name=fixture.bin\r\nklm\r\n=yend size=3 crc32=a3830348\r\n";
        fs::write(source.join("data"), data).unwrap();
        fs::write(source.join("article"), article).unwrap();
        let manifest = json!({"schema_version":1,"size":3,"density":"ordinary","data_bytes":3,
            "article_bytes":article.len(),"data_sha256":digest(data),"article_sha256":digest(article)});
        fs::write(
            source.join("manifest.json"),
            serde_json::to_vec(&manifest).unwrap(),
        )
        .unwrap();
        let frozen = freeze_fixture(&source, &output, 3, "ordinary").unwrap();
        assert_eq!(frozen.decoded_bytes, 3);
        assert_eq!(frozen.crc32, 0xa3830348);
        assert_eq!(fs::read(&frozen.data_path).unwrap(), data);
        assert!(freeze_fixture(&source, &output, 3, "ordinary").is_err());
        assert!(freeze_fixture(&source, &output, usize::MAX, "ordinary").is_err());
        assert!(freeze_fixture(&source, &output, 3, "heavy").is_err());
        fs::write(source.join("data"), b"ABD").unwrap();
        assert!(freeze_fixture(&source, &output, 3, "ordinary").is_err());
        assert_eq!(fs::read(&frozen.data_path).unwrap(), data);
    }

    #[test]
    fn control_options_reject_unknown_duplicate_unbounded_or_missing_arguments() {
        let names = [
            "fixtures",
            "library-a",
            "library-b",
            "manifest-a",
            "manifest-b",
            "worker",
            "python",
            "cpu-snapshot",
            "output",
            "source-root",
        ];
        let args: Vec<_> = names
            .iter()
            .map(|name| format!("--{name}=/tmp/control/{name}"))
            .collect();
        let parsed = options(&args).unwrap();
        assert_eq!(parsed.samples, 31);
        assert_eq!(parsed.iterations, 8);
        assert_eq!(parsed.paths["source-root"], "/tmp/control/source-root");
        assert_eq!(parsed.timeout, 30);
        for extra in [
            "--samples=0",
            "--samples=102",
            "--iterations=1001",
            "--timeout=301",
            "--backend=cpp",
            "--fixtures=/tmp/again",
        ] {
            let mut bad = args.clone();
            bad.push(extra.into());
            assert!(options(&bad).is_err(), "{extra}");
        }
        assert!(options(&args[..9]).is_err());
    }

    #[test]
    fn source_freeze_checks_complete_rust_inventory_and_actual_cargo_generator_bytes() {
        let scratch = Scratch::new();
        let root = &scratch.0;
        let output = root.join("frozen");
        fs::create_dir(&output).unwrap();
        let crate_root = root.join("docker/app/nzb-yenc-ffi");
        fs::create_dir_all(crate_root.join("src")).unwrap();
        fs::create_dir(root.join("scripts")).unwrap();
        fs::create_dir_all(root.join("tests/Fixtures")).unwrap();
        for name in ["src/lib.rs", "Cargo.lock", "Cargo.toml"] {
            fs::write(crate_root.join(name), b"ABC").unwrap();
        }
        fs::write(root.join("scripts/profile-yenc.php"), b"ABC").unwrap();
        fs::write(root.join("scripts/benchmark-yenc-abi.py"), b"ABC").unwrap();
        fs::write(root.join("tests/Fixtures/YencArticles.php"), b"ABC").unwrap();
        let hash = "b5d4045c3f466fa91fe2cc6abe79232a1a57cdf104f7a26e716e0a1e2789df78";
        let manifest = json!({"rust_source_sha256":{"src/lib.rs":hash},"cargo_lock_sha256":hash,"cargo_toml_sha256":hash,
            "fixture_generator_sha256":hash,"fixture_encoder_sha256":hash,"worker_sha256":hash});
        let receipts = freeze_sources(root, &output, &manifest).unwrap();
        let mut substituted = manifest.clone();
        substituted["worker_sha256"] = json!("0".repeat(64));
        let untrusted = root.join("substituted-worker");
        fs::create_dir(&untrusted).unwrap();
        assert!(freeze_sources(root, &untrusted, &substituted).is_err());
        assert_eq!(receipts.as_array().unwrap().len(), 6);

        fs::write(crate_root.join("src/missing.rs"), b"ABC").unwrap();
        let second = root.join("second");
        fs::create_dir(&second).unwrap();
        assert!(freeze_sources(root, &second, &manifest).is_err());
    }

    #[test]
    fn schedule_keeps_failed_pairs_and_fixed_rust_roles_without_survivor_comparisons() {
        let scratch = Scratch::new();
        let path = scratch.0.join("observations.jsonl");
        let mut out = exclusive(&path).unwrap();
        let fixtures = [Fixture {
            name: "literal".into(),
            data_path: "unused".into(),
            article_path: "unused".into(),
            preparation_manifest_sha256: "manifest".into(),
            data_sha256: "data".into(),
            article_sha256: "article".into(),
            decoded_bytes: 8,
            crc32: 0x12345678,
            crc_seed: 0x11111111,
        }];
        let context = ScheduleContext {
            fixtures: &fixtures,
            library_sha: "library",
            worker_sha: "worker",
            iterations: 8,
        };
        let steps = [
            Step {
                fixture_index: 0,
                operation: "decode-lined".into(),
                sample: 0,
                order: ["control_b", "control_a"],
            },
            Step {
                fixture_index: 0,
                operation: "decode-lined".into(),
                sample: 1,
                order: ["control_a", "control_b"],
            },
        ];
        let result = run_schedule(&steps, &context, &mut out, |_, _, sample, slot| {
            if sample == 1 && slot == "control_b" {
                Err("retained test executor failure".into())
            } else {
                Ok(observation())
            }
        })
        .unwrap();
        assert_eq!(result["eligible_pairs"], 1);
        assert_eq!(result["invalid_pairs"], 1);
        let text = fs::read_to_string(&path).unwrap();
        let records: Vec<Value> = text
            .lines()
            .map(|s| serde_json::from_str(s).unwrap())
            .collect();
        let pairs: Vec<_> = records.iter().filter(|r| r["type"] == "pair").collect();
        assert_eq!(pairs.len(), 2);
        assert_eq!(pairs[0]["order"], json!(["control_b", "control_a"]));
        assert_eq!(pairs[0]["eligible"], true);
        assert_eq!(pairs[1]["eligible"], false);
        assert!(pairs[1]["latency_ratio_control_a_over_b"].is_null());
        for record in records.iter().filter(|r| r["type"] == "observation") {
            assert_eq!(record["backend"], "rust");
        }
        assert_eq!(
            records
                .iter()
                .filter(|r| r["type"] == "observation")
                .count(),
            4
        );
        assert!(!text.contains("median_rust_over_cpp"));
    }

    #[test]
    fn admission_requires_fixture_encoder_identity_and_finite_pair_ratio() {
        let mut m = manifest();
        m.as_object_mut().unwrap().remove("fixture_encoder_sha256");
        assert!(admit(&m, &m, &"a".repeat(64), &"b".repeat(64)).is_err());
        let mut a = observation();
        let mut b = a.clone();
        a["result"]["per_call_ns"] = json!(1e308);
        b["result"]["per_call_ns"] = json!(1e-308);
        let pair = pair_record(&a, &b, &expected());
        assert_eq!(pair["eligible"], false);
        assert!(pair["latency_ratio_control_a_over_b"].is_null());
    }

    #[test]
    fn untimed_probe_requires_same_library_and_neon_not_merely_a_rust_label() {
        let good = json!({"status":"ok","exit_code":0,"stderr_bytes":0,"result":{"kernel":4,"library_sha256":"frozen"}});
        assert!(kernel_verified(&good, "frozen"));
        let mut wrong = good.clone();
        wrong["result"]["kernel"] = json!(2);
        assert!(!kernel_verified(&wrong, "frozen"));
        assert!(!kernel_verified(&good, "different"));
        let mut wrong = good;
        wrong["status"] = json!("timeout");
        assert!(!kernel_verified(&wrong, "frozen"));
    }

    #[test]
    fn cpu_endpoint_runner_accepts_empty_stdout_without_weakening_json_workers() {
        let scratch = Scratch::new();
        let raw = run_child_raw(
            Command::new("/bin/sh").args(["-c", "exit 0"]),
            &scratch.0.join("raw"),
            Duration::from_secs(1),
        )
        .unwrap();
        assert_eq!(raw["status"], "ok");
        assert!(raw["result"].is_null());
        let strict = run_child(
            Command::new("/bin/sh").args(["-c", "exit 0"]),
            &scratch.0.join("strict"),
            Duration::from_secs(1),
        )
        .unwrap();
        assert_eq!(strict["status"], "invalid_json");
    }

    #[test]
    fn raw_python_json_timing_rounding_preserves_integer_batch_authority() {
        for (literal, iterations) in [
            (
                r#"{"batch_elapsed_ns":301,"iterations":3,"per_call_ns":100.33333333333333}"#,
                3,
            ),
            (
                r#"{"batch_elapsed_ns":1000000,"iterations":11,"per_call_ns":90909.09090909091}"#,
                11,
            ),
        ] {
            let timing: Value = serde_json::from_str(literal).unwrap();
            let mut record = observation();
            for key in ["batch_elapsed_ns", "iterations", "per_call_ns"] {
                record["result"][key] = timing[key].clone();
            }
            record["result"]["timed_call_results"] = json!(vec![8; iterations as usize]);
            let mut requirement = expected();
            requirement.iterations = iterations;
            assert!(eligible(&record, &requirement), "{literal}");
            let paired = pair_record(&record, &record, &requirement);
            assert_eq!(paired["latency_ratio_control_a_over_b"], 1.0);
            record["result"]["per_call_ns"] =
                json!(timing["per_call_ns"].as_f64().unwrap() * 1.0000001);
            assert!(!eligible(&record, &requirement));
        }
    }

    #[test]
    fn timing_requires_positive_integer_batch_and_consistent_derived_per_call_duration() {
        let good = observation();
        assert!(eligible(&good, &expected()));
        for bad_duration in [Value::Null, json!(0), json!(801), json!("800")] {
            let mut bad = good.clone();
            bad["result"]["batch_elapsed_ns"] = bad_duration;
            assert!(!eligible(&bad, &expected()));
        }
        let mut bad = good.clone();
        bad["result"]["per_call_ns"] = json!(99.9);
        assert!(!eligible(&bad, &expected()));
        let mut crc = good;
        crc["result"]["operation"] = json!("crc-continuation");
        crc["result"]["warm_output_probe"] = Value::Null;
        crc["result"]["output_probe"] = Value::Null;
        crc["result"]["last_result"] = json!(0x12345678u32);
        crc["result"]["timed_call_results"] = json!(vec![0x12345678u32; 8]);
        let mut expected_crc = expected();
        expected_crc.operation = "crc-continuation";
        assert!(eligible(&crc, &expected_crc));
    }

    #[test]
    fn build_admission_requires_cargo_build_and_an_actual_feature_option() {
        for argv in [
            json!([
                "cargo",
                "build",
                "--release",
                "--target-dir",
                "experimental-payload-kernels"
            ]),
            json!([
                "echo",
                "--release",
                "--features",
                "experimental-payload-kernels"
            ]),
            json!([
                "cargo",
                "build",
                "--release",
                "--",
                "--features",
                "experimental-payload-kernels"
            ]),
            json!([
                "cargo",
                "build",
                "--release",
                "--features",
                "other",
                "experimental-payload-kernels"
            ]),
        ] {
            let mut m = manifest();
            m["build_argv"] = argv;
            assert!(admit(&m, &m, &"a".repeat(64), &"b".repeat(64)).is_err());
        }
        let mut m = manifest();
        m["build_argv"] = json!([
            "cargo",
            "+1.98.0",
            "build",
            "--locked",
            "--release",
            "--features=experimental-payload-kernels"
        ]);
        assert!(admit(&m, &m, &"a".repeat(64), &"b".repeat(64)).is_ok());
    }

    #[test]
    fn completion_requires_a_valid_observed_cpu_interval_as_well_as_valid_pairs() {
        let before = json!({"label":"before","receipt":{"status":"ok","exit_code":0,"stderr_bytes":0},
            "endpoint":{"schema_version":1,"monotonic_ns":100,"os_cpu_usable":true,"os":{"stat":{"status":"captured","raw":"cpu 1 2 3 4\n"}}}});
        let after = json!({"label":"after","receipt":{"status":"ok","exit_code":0,"stderr_bytes":0},
            "endpoint":{"schema_version":1,"monotonic_ns":200,"os_cpu_usable":true,"os":{"stat":{"status":"captured","raw":"cpu 2 3 4 5\n"}}}});
        assert_eq!(
            final_control_status(true, 0, &before, &after),
            "control_observations_complete"
        );
        for variant in 0..7 {
            let mut bad = after.clone();
            match variant {
                0 => bad["endpoint"] = Value::Null,
                1 => bad["receipt"]["status"] = json!("child_failed"),
                2 => bad["endpoint"]["os_cpu_usable"] = json!(false),
                3 => bad["endpoint"]["monotonic_ns"] = json!(100),
                4 => bad["endpoint"]["schema_version"] = json!(2),
                5 => bad["endpoint"]["os"]["stat"]["raw"] = json!("cpu 0 1 2 3\n"),
                _ => bad["endpoint"]["os"]["stat"]["raw"] = json!("cpu 1 nope 3 4\n"),
            }
            assert_ne!(
                final_control_status(true, 0, &before, &bad),
                "control_observations_complete",
                "{variant}"
            );
            assert_ne!(
                final_control_status(true, 0, &bad, &after),
                "control_observations_complete",
                "{variant}"
            );
        }
        assert_ne!(
            final_control_status(true, 1, &before, &after),
            "control_observations_complete"
        );
        assert_ne!(
            final_control_status(false, 0, &before, &after),
            "control_observations_complete"
        );
    }

    #[test]
    fn source_inventory_shares_global_entry_and_byte_budgets_through_recursion() {
        let scratch = Scratch::new();
        fs::create_dir(scratch.0.join("nested")).unwrap();
        fs::write(scratch.0.join("one.rs"), b"ABC").unwrap();
        fs::write(scratch.0.join("nested/two.rs"), b"ABC").unwrap();
        let mut found = std::collections::BTreeMap::new();
        let mut exhausted = SourceBudget {
            entries: 2048,
            bytes: 0,
        };
        assert!(rust_inventory(&scratch.0, "src", 0, &mut found, &mut exhausted).is_err());
        let mut exhausted = SourceBudget {
            entries: 0,
            bytes: 33_554_431,
        };
        assert!(rust_inventory(&scratch.0, "src", 0, &mut found, &mut exhausted).is_err());
        found.clear();
        let mut budget = SourceBudget::default();
        rust_inventory(&scratch.0, "src", 0, &mut found, &mut budget).unwrap();
        assert_eq!(budget.entries, 3);
        assert_eq!(budget.bytes, 6);
        assert_eq!(found.len(), 2);
    }

    #[test]
    fn frozen_build_manifests_remain_bound_to_final_identity_validation() {
        let scratch = Scratch::new();
        let path = scratch.0.join("runtime-a.json");
        let mut identities = Vec::new();
        freeze_bound_identity(&path, b"{}", 4096, &mut identities).unwrap();
        let before = identity_receipts(&identities);
        assert_eq!(before.as_array().unwrap().len(), 1);
        assert_eq!(before[0]["matches"], true);
        fs::write(&path, b"[]").unwrap();
        assert_eq!(identity_receipts(&identities)[0]["matches"], false);
        assert!(
            freeze_bound_identity(&scratch.0.join("oversize"), b"{}", 1, &mut identities).is_err()
        );
    }

    #[test]
    fn child_retained_files_never_exceed_the_child_only_file_size_limit() {
        let scratch = Scratch::new();
        let mut before = FileLimit {
            current: 0,
            maximum: 0,
        };
        // SAFETY: getrlimit writes one correctly laid out live FileLimit.
        assert_eq!(unsafe { getrlimit(1, &mut before) }, 0);
        for (name, command, field) in [
            ("stdout", "exec head -c 1000000 /dev/zero", "stdout_bytes"),
            (
                "stderr",
                "exec head -c 1000000 /dev/zero >&2",
                "stderr_bytes",
            ),
        ] {
            let record = run_child(
                Command::new("/bin/sh").args(["-c", command]),
                &scratch.0.join(name),
                Duration::from_secs(2),
            )
            .unwrap();
            assert!(record[field].as_u64().unwrap() <= RETAINED_OUTPUT_CAP as u64);
            assert_eq!(record["status"], "output_limit_reached");
            assert_eq!(record["direct_child_reaped"], true);
        }
        let mut after = FileLimit {
            current: 0,
            maximum: 0,
        };
        // SAFETY: same live, correctly laid out writable output as above.
        assert_eq!(unsafe { getrlimit(1, &mut after) }, 0);
        assert_eq!(before, after);
    }

    fn manifest() -> Value {
        json!({"comparison_kind":"rust-aa-control", "git_head":"1".repeat(40),
            "rust_payload_mode":"experimental", "library_sha256":"a".repeat(64),
            "worker_sha256":"b".repeat(64), "rust_source_sha256":{"src/lib.rs":"c".repeat(64)},
            "cargo_lock_sha256":"d".repeat(64), "cargo_toml_sha256":"e".repeat(64),
            "fixture_generator_sha256":"f".repeat(64), "fixture_encoder_sha256":"0".repeat(64), "rustc":"rustc 1.98.0", "cargo":"cargo 1.98.0",
            "build_argv":["cargo","build","--release","--features","experimental-payload-kernels"],
            "build_env":{}, "runner_identity":{"os":"linux","arch":"aarch64"}})
    }

    fn expected() -> Expected<'static> {
        Expected {
            data_sha: "data",
            article_sha: "article",
            library_sha: "library",
            worker_sha: "worker",
            decoded_bytes: 8,
            crc32: 0x12345678,
            crc_seed: 0x11111111,
            operation: "decode-lined",
            iterations: 8,
        }
    }

    fn observation() -> Value {
        let probe = json!({"exact_output":true,"output_sha256":"data", "prefix_capacity_canary_intact":true,
            "suffix_capacity_canary_intact":true,"unused_capacity_changed_bytes":0});
        json!({"status":"ok","exit_code":0,"stderr_bytes":0,"result":{
            "status":"ok","backend":"rust","operation":"decode-lined","iterations":8,
            "data_sha256":"data","article_sha256":"article","script_sha256":"worker",
            "library":{"sha256":"library"},"per_call_ns":100.0,"batch_elapsed_ns":800,
            "expected_crc32":"12345678","continuation_seed_crc32":"11111111","zero_length_crc32":"11111111","last_result":8,"timed_call_results":[8,8,8,8,8,8,8,8],
            "warm_output_probe":probe,"output_probe":probe}})
    }

    #[test]
    fn admits_only_identical_complete_rust_control_manifests() {
        let left = manifest();
        let hash = "a".repeat(64);
        let worker = "b".repeat(64);
        assert_eq!(admit(&left, &left, &hash, &worker), Ok(()));
        let mut right = left.clone();
        right["library_sha256"] = json!("0".repeat(64));
        assert!(admit(&left, &right, &hash, &worker).is_err());
        for key in [
            "rust_source_sha256",
            "cargo_lock_sha256",
            "cargo_toml_sha256",
            "fixture_generator_sha256",
            "rustc",
            "cargo",
            "build_argv",
            "build_env",
            "runner_identity",
        ] {
            let mut missing = left.clone();
            missing.as_object_mut().unwrap().remove(key);
            assert!(admit(&missing, &missing, &hash, &worker).is_err(), "{key}");
        }
        let mut wrong = left.clone();
        wrong["comparison_kind"] = json!("cpp-rust");
        assert!(admit(&wrong, &wrong, &hash, &worker).is_err());
    }

    #[test]
    fn both_slots_require_rust_exact_output_and_all_frozen_identities() {
        let good = observation();
        assert!(eligible(&good, &expected()));
        for slot in ["control_a", "control_b"] {
            let mut bad = good.clone();
            bad["result"]["output_probe"]["unused_capacity_changed_bytes"] = json!(1);
            assert!(!eligible(&bad, &expected()), "{slot}");
            let mut bad = good.clone();
            bad["result"]["warm_output_probe"]["unused_capacity_changed_bytes"] = json!(1);
            assert!(!eligible(&bad, &expected()), "{slot}");
        }
        for key in [
            "data_sha256",
            "article_sha256",
            "script_sha256",
            "backend",
            "operation",
        ] {
            let mut bad = good.clone();
            bad["result"][key] = json!("changed");
            assert!(!eligible(&bad, &expected()), "{key}");
        }
        let mut bad = good.clone();
        bad["result"]["library"]["sha256"] = json!("changed");
        assert!(!eligible(&bad, &expected()));
        let mut bad = good.clone();
        bad["result"]["timed_call_results"][0] = json!(7);
        assert!(!eligible(&bad, &expected()));
        let mut bad = good.clone();
        bad["stderr_bytes"] = json!(1);
        assert!(!eligible(&bad, &expected()));
        let mut bad = good;
        bad["status"] = json!("timeout");
        assert!(!eligible(&bad, &expected()));
    }

    #[test]
    fn pair_ratio_requires_both_members_of_the_same_control_pair() {
        let left = observation();
        let mut right = left.clone();
        right["result"]["per_call_ns"] = json!(50.0);
        right["result"]["batch_elapsed_ns"] = json!(400);
        let pair = pair_record(&left, &right, &expected());
        assert_eq!(pair["eligible"], true);
        assert_eq!(pair["latency_ratio_control_a_over_b"], 2.0);
        right["result"]["status"] = json!("incorrect");
        let pair = pair_record(&left, &right, &expected());
        assert_eq!(pair["eligible"], false);
        assert!(pair["latency_ratio_control_a_over_b"].is_null());
        let mut crc = expected();
        crc.operation = "crc-whole";
        assert!(!eligible(&left, &crc));
    }
}
