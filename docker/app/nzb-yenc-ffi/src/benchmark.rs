//! Frozen paired performance qualification through explicit native artifacts.
//!
//! Each timed observation owns a fresh child. Every child is retained before its
//! report is interpreted; wait4 is the sole reaper and supplies per-child peak RSS.
use crate::tooling::{Library, file_sha256, summarize};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::ffi::{CStr, c_char, c_int, c_long, c_void};
use std::fs::{self, OpenOptions};
use std::hint::black_box;
use std::io::Write;
use std::os::fd::AsRawFd;
use std::os::unix::process::CommandExt;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

type Options = BTreeMap<String, String>;
const SEED: u32 = 582139;
const REPETITIONS: usize = 5;
const WARMUPS: usize = 2;
const OBSERVATIONS: usize = 11;
const MIN_BATCH_MS: f64 = 250.;
const CHILD_TIMEOUT: Duration = Duration::from_secs(120);
const PROTOCOL: &str = "rust-paired-yenc-v4";
const CHUNK_MEMORY_LIMIT: usize = 32 * 1024 * 1024;
const MAX_CHUNK_SLOTS: usize = 8;
const SCALAR_CHUNK_SLOTS: usize = 4096;
const METADATA_SLOT_LIMIT: usize = 1024 * 1024;

fn required<'a>(options: &'a Options, key: &str) -> Result<&'a str, String> {
    options
        .get(key)
        .map(String::as_str)
        .ok_or_else(|| format!("missing {key}"))
}
fn hash(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}
fn write_json(path: &Path, value: &Value) -> Result<(), String> {
    let mut file = OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(path)
        .map_err(|e| format!("{}: {e}", path.display()))?;
    file.write_all(
        serde_json::to_string_pretty(value)
            .map_err(|e| e.to_string())?
            .as_bytes(),
    )
    .map_err(|e| e.to_string())?;
    file.sync_all().map_err(|e| e.to_string())
}
#[cfg(test)]
fn read_json(path: &Path) -> Result<Value, String> {
    serde_json::from_slice(&fs::read(path).map_err(|e| format!("{}: {e}", path.display()))?)
        .map_err(|e| format!("{}: {e}", path.display()))
}
fn positive(value: &Value, key: &str) -> Result<f64, String> {
    value[key]
        .as_f64()
        .filter(|v| v.is_finite() && *v > 0.)
        .ok_or_else(|| format!("invalid positive {key}"))
}

/// Reject successful exit codes with incorrect, incomplete or unrelated reports.
pub(crate) fn validate_php(
    report: &Value,
    artifact: &str,
    fixture: &str,
    bytes: usize,
) -> Result<Vec<f64>, String> {
    if report["status"] != "ok"
        || report["library_sha256"] != artifact
        || !report["decoder_version"].is_string()
    {
        return Err("PHP status/native identity mismatch".into());
    }
    positive(report, "peak_rss_bytes")?;
    let low = positive(report, "min_ms")?;
    let median = positive(report, "median_ms")?;
    let high = positive(report, "max_ms")?;
    if !(low <= median && median <= high) {
        return Err("PHP sorted times invalid".into());
    }
    let Some(observations) = report.get("observations") else {
        // Historical reports have only sorted values. Preserve that provenance.
        return Ok(vec![low, median, high]);
    };
    let observations = observations
        .as_array()
        .ok_or("PHP observations not array")?;
    if observations.len() != 4 {
        return Err("PHP must report one warmup and three calls".into());
    }
    let mut times = Vec::new();
    for (i, observation) in observations.iter().enumerate() {
        if observation["sample"].as_i64() != Some(i as i64 - 1)
            || observation["warmup"].as_bool() != Some(i == 0)
            || observation["correct"] != true
            || observation["decoded_bytes"].as_u64() != Some(bytes as u64)
            || observation["decoded_sha256"] != fixture
        {
            return Err("PHP ordered correctness observation invalid".into());
        }
        let time = positive(observation, "elapsed_ms")?;
        if i > 0 {
            times.push(time);
        }
    }
    let mut sorted = times.clone();
    sorted.sort_by(f64::total_cmp);
    if sorted != [low, median, high] {
        return Err("PHP ordered and sorted durations disagree".into());
    }
    Ok(times)
}

pub(crate) fn decision(pairs: &[Value], improvement: Option<f64>) -> Result<bool, String> {
    if pairs.len() != REPETITIONS {
        return Err("five complete pairs required".into());
    }
    let mut faster = 0;
    for pair in pairs {
        let median = positive(pair, "median_ratio")?;
        if positive(pair, "p95_ratio")? > 1.05 || positive(pair, "rss_ratio")? > 1.05 {
            return Ok(false);
        }
        if let Some(target) = improvement {
            if median > 1. {
                return Ok(false);
            }
            if median <= target {
                faster += 1;
            }
        } else if median > 1.05 {
            return Ok(false);
        }
    }
    Ok(improvement.is_none() || faster >= 4)
}

// Both supported OS ABIs lay out two 16-byte timevals followed by fourteen
// longs. Darwin's 32-bit tv_usec plus padding occupies the same eight bytes;
// only ru_maxrss is read. Aggregate RUSAGE_CHILDREN would mix observations.
#[repr(C)]
#[derive(Default)]
struct RUsage {
    timevals: [c_long; 4],
    max_rss: c_long,
    other: [c_long; 13],
}
unsafe extern "C" {
    fn wait4(pid: c_int, status: *mut c_int, options: c_int, usage: *mut RUsage) -> c_int;
    fn kill(pid: c_int, signal: c_int) -> c_int;
    fn getpid() -> c_int;
    fn getppid() -> c_int;
    fn write(fd: c_int, bytes: *const c_void, count: usize) -> isize;
    #[cfg(target_os = "linux")]
    fn prctl(option: c_int, ...) -> c_int;
}

// A killed collector must not leave worker descendants behind. Linux adopts
// orphaned descendants here; reap only the explicitly owned process group.
fn become_subreaper() -> Result<(), String> {
    #[cfg(target_os = "linux")]
    if unsafe { prctl(36, 1 as c_long, 0 as c_long, 0 as c_long, 0 as c_long) } != 0 {
        return Err(format!("subreaper: {}", std::io::Error::last_os_error()));
    }
    Ok(())
}
struct OwnedGroup(c_int);
impl Drop for OwnedGroup {
    fn drop(&mut self) {
        unsafe {
            kill(-self.0, 9);
        }
        let mut status = 0;
        let mut usage = RUsage::default();
        loop {
            let reaped = unsafe { wait4(-self.0, &mut status, 0, &mut usage) };
            if reaped < 0
                && std::io::Error::last_os_error().kind() != std::io::ErrorKind::Interrupted
            {
                break;
            }
        }
    }
}
fn json_file(path: &Path) -> Result<Value, String> {
    serde_json::from_slice(&fs::read(path).map_err(|e| format!("{}: {e}", path.display()))?)
        .map_err(|e| format!("{}: {e}", path.display()))
}
// Exec a small collector before it forks the real worker. Its own wait4 RSS may
// inherit this parent's resident floor and is retained only as a diagnostic.
fn capture_fresh(
    command: &mut Command,
    executable: &Path,
    directory: &Path,
    timeout: Duration,
) -> Result<ChildReceipt, String> {
    become_subreaper()?;
    fs::create_dir(directory).map_err(|e| e.to_string())?;
    let request = json!({"program":command.get_program().to_str().ok_or("non-UTF8 program")?,
        "arguments":command.get_args().map(|a|a.to_str().ok_or("non-UTF8 argument")).collect::<Result<Vec<_>,_>>()?,
        "cwd":command.get_current_dir().map(|p|p.to_str().ok_or("non-UTF8 cwd")).transpose()?,
        "environment":command.get_envs().map(|(k,v)|Ok((k.to_str().ok_or("non-UTF8 env key")?,v.map(|s|s.to_str().ok_or("non-UTF8 env value")).transpose()?))).collect::<Result<BTreeMap<_,_>, &str>>()?,
        "timeout_ms":timeout.as_millis()});
    write_json(&directory.join("capture-request.json"), &request)?;
    let mut collector = Command::new(executable);
    collector
        .arg("capture-child")
        .arg("--request")
        .arg(directory.join("capture-request.json"))
        .arg("--directory")
        .arg(directory);
    let collected = capture(
        &mut collector,
        &directory.join("collector"),
        timeout + Duration::from_secs(5),
    );
    // PID is written in the worker's pre-exec hook, before it can create children.
    // The parent therefore knows the owned group even if the broker dies.
    let worker_pid = fs::read_to_string(directory.join("worker.pid"))
        .ok()
        .and_then(|s| s.parse::<c_int>().ok())
        .filter(|pid| *pid > 0);
    if collected.is_err()
        && let Some(pid) = worker_pid
    {
        drop(OwnedGroup(pid));
    }
    let collector_outcome = json_file(&directory.join("collector/outcome.json"))?;
    write_json(
        &directory.join("collector.json"),
        &json!({"rss_role":"diagnostic_inherited_parent_floor",
        "worker_pid":worker_pid,"collector_outcome":collector_outcome,"error":collected.as_ref().err()}),
    )?;
    let collected = collected?;
    let outcome = json_file(&directory.join("outcome.json"))?;
    for (field, file) in [
        ("worker_outcome_sha256", "outcome.json"),
        ("worker_stdout_sha256", "stdout"),
        ("worker_stderr_sha256", "stderr"),
    ] {
        if collected.report[field] != file_sha256(&directory.join(file))? {
            return Err(format!("collector {field} mismatch"));
        }
    }
    if outcome["status"] != "reported"
        || outcome["report"]["status"] != "ok"
        || outcome["timeout"] != false
        || outcome["exit_code"] != 0
        || outcome["signal"] != 0
        || outcome["stderr_bytes"] != 0
        || collected.report["worker_lifetime_ms"] != outcome["lifetime_ms"]
        || collected.report["worker_peak_rss_bytes"] != outcome["peak_rss_bytes"]
        || collected.report["worker_pid"] != json!(worker_pid)
    {
        return Err("invalid collector worker receipt".into());
    }
    let lifetime_ms = positive(&outcome, "lifetime_ms")?;
    let rss_bytes = outcome["peak_rss_bytes"]
        .as_u64()
        .filter(|n| *n > 0)
        .ok_or("invalid worker RSS")?;
    Ok(ChildReceipt {
        report: outcome["report"].clone(),
        lifetime_ms,
        rss_bytes,
    })
}
/// Internal fresh-process broker. It parses only a small command request, never
/// the correctness corpus or accumulated paired evidence before worker spawn.
pub(crate) fn capture_child(options: &Options) -> Result<Value, String> {
    if options.len() != 2 {
        return Err("capture-child needs request and directory only".into());
    }
    let request_path = Path::new(required(options, "--request")?);
    if fs::metadata(request_path).map_err(|e| e.to_string())?.len() > 16 * 1024 {
        return Err("capture request exceeds small collector limit".into());
    }
    let request = json_file(request_path)?;
    let directory = Path::new(required(options, "--directory")?);
    let mut command = Command::new(
        request["program"]
            .as_str()
            .ok_or("invalid capture program")?,
    );
    for arg in request["arguments"]
        .as_array()
        .ok_or("invalid capture arguments")?
    {
        command.arg(arg.as_str().ok_or("invalid capture argument")?);
    }
    if let Some(cwd) = request["cwd"].as_str() {
        command.current_dir(cwd);
    }
    for (key, value) in request["environment"]
        .as_object()
        .ok_or("invalid capture environment")?
    {
        if value.is_null() {
            command.env_remove(key);
        } else {
            command.env(
                key,
                value.as_str().ok_or("invalid capture environment value")?,
            );
        }
    }
    let timeout = request["timeout_ms"]
        .as_u64()
        .filter(|n| *n > 0 && *n <= 120000)
        .ok_or("invalid capture timeout")?;
    let child = capture(&mut command, directory, Duration::from_millis(timeout))?;
    Ok(
        json!({"status":"ok","worker_pid":fs::read_to_string(directory.join("worker.pid")).map_err(|e|e.to_string())?.parse::<c_int>().map_err(|e|e.to_string())?,
        "worker_outcome_sha256":file_sha256(&directory.join("outcome.json"))?,
        "worker_stdout_sha256":file_sha256(&directory.join("stdout"))?,
        "worker_stderr_sha256":file_sha256(&directory.join("stderr"))?,
        "worker_lifetime_ms":child.lifetime_ms,"worker_peak_rss_bytes":child.rss_bytes}),
    )
}

fn machine_conditions() -> Value {
    let read = |path: &str| fs::read_to_string(path).ok();
    let processes = fs::read_dir("/proc").ok().map(|entries| {
        entries
            .filter_map(Result::ok)
            .filter(|entry| {
                entry
                    .file_name()
                    .to_string_lossy()
                    .bytes()
                    .all(|b| b.is_ascii_digit())
            })
            .filter_map(|entry| fs::read_to_string(entry.path().join("stat")).ok())
            .collect::<Vec<_>>()
    });
    json!({"arch":std::env::consts::ARCH,"os":std::env::consts::OS,
        "unix_time_ms":std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).ok().map(|d|d.as_millis()),
        "loadavg":read("/proc/loadavg"),"cpu_stat":read("/proc/stat"),"cpu_pressure":read("/proc/pressure/cpu"),
        "process_stat_without_arguments":processes})
}

struct ChildReceipt {
    report: Value,
    lifetime_ms: f64,
    rss_bytes: u64,
}
fn capture(
    command: &mut Command,
    directory: &Path,
    timeout: Duration,
) -> Result<ChildReceipt, String> {
    become_subreaper()?;
    fs::create_dir_all(directory).map_err(|e| format!("{}: {e}", directory.display()))?;
    let stdout = OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(directory.join("stdout"))
        .map_err(|e| e.to_string())?;
    let stderr = OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(directory.join("stderr"))
        .map_err(|e| e.to_string())?;
    let command_receipt = json!({"program":command.get_program().to_string_lossy(),
        "arguments":command.get_args().map(|a|a.to_string_lossy().into_owned()).collect::<Vec<_>>(),
        "timeout_ms":timeout.as_millis(),"rss_method":"wait4", "os":std::env::consts::OS,"machine_conditions":machine_conditions()});
    write_json(&directory.join("command.json"), &command_receipt)?;
    command
        .stdin(Stdio::null())
        .stdout(stdout)
        .stderr(stderr)
        .process_group(0);
    let pid_file = OpenOptions::new()
        .create_new(true)
        .write(true)
        .open(directory.join("worker.pid"))
        .map_err(|e| e.to_string())?;
    let pid_fd = pid_file.as_raw_fd();
    let parent_pid = std::process::id() as c_int;
    // Only async-signal-safe operations run between fork and exec. Persist the
    // group ID before exec; PDEATHSIG closes the broker-death startup race.
    unsafe {
        command.pre_exec(move || {
            #[cfg(target_os = "linux")]
            if prctl(1, 9 as c_long, 0 as c_long, 0 as c_long, 0 as c_long) != 0 {
                return Err(std::io::Error::last_os_error());
            }
            if getppid() != parent_pid {
                return Err(std::io::Error::from_raw_os_error(10));
            }
            let mut digits = [0u8; 20];
            let mut index = digits.len();
            let mut pid = getpid() as u32;
            loop {
                index -= 1;
                digits[index] = b'0' + (pid % 10) as u8;
                pid /= 10;
                if pid == 0 {
                    break;
                }
            }
            let mut offset = index;
            while offset < digits.len() {
                let n = write(
                    pid_fd,
                    digits[offset..].as_ptr().cast(),
                    digits.len() - offset,
                );
                if n < 0 {
                    if std::io::Error::last_os_error().kind() == std::io::ErrorKind::Interrupted {
                        continue;
                    }
                    return Err(std::io::Error::last_os_error());
                }
                if n == 0 {
                    return Err(std::io::Error::from_raw_os_error(5));
                }
                offset += n as usize;
            }
            Ok(())
        });
    }
    let start = Instant::now();
    let mut child = match command.spawn() {
        Ok(child) => child,
        Err(e) => {
            write_json(
                &directory.join("outcome.json"),
                &json!({"status":"spawn_failed","error":e.to_string()}),
            )?;
            return Err(format!(
                "child spawn failed, retained at {}: {e}",
                directory.display()
            ));
        }
    };
    let pid = child.id() as c_int;
    let owned_group = OwnedGroup(pid);
    let mut usage = RUsage::default();
    let mut status = 0;
    let mut timeout_hit = false;
    loop {
        let observed = unsafe { wait4(pid, &mut status, 1, &mut usage) };
        if observed == pid {
            break;
        }
        if observed < 0 {
            let error = std::io::Error::last_os_error();
            if error.kind() == std::io::ErrorKind::Interrupted {
                continue;
            }
            unsafe {
                kill(-pid, 9);
            }
            let _ = child.kill();
            // Reap our killed child when the original error did not already
            // indicate a missing child. This cleanup is never a retry.
            loop {
                let reaped = unsafe { wait4(pid, &mut status, 0, &mut usage) };
                if reaped == pid {
                    break;
                }
                if reaped < 0
                    && std::io::Error::last_os_error().kind() != std::io::ErrorKind::Interrupted
                {
                    break;
                }
            }
            write_json(
                &directory.join("outcome.json"),
                &json!({"status":"wait4_failed","error":error.to_string()}),
            )?;
            return Err(format!("wait4 failed: {error}"));
        }
        if start.elapsed() >= timeout && !timeout_hit {
            timeout_hit = true;
            unsafe {
                kill(-pid, 9);
            }
        }
        std::thread::sleep(Duration::from_micros(100));
    }
    let lifetime_ms = start.elapsed().as_secs_f64() * 1000.;
    drop(owned_group);
    let rss_units = if cfg!(target_os = "macos") {
        "bytes"
    } else {
        "KiB"
    };
    let rss_bytes =
        (usage.max_rss.max(0) as u64) * if cfg!(target_os = "macos") { 1 } else { 1024 };
    let signal = status & 0x7f;
    let exit = if signal == 0 {
        Some((status >> 8) & 255)
    } else {
        None
    };
    let stdout = fs::read(directory.join("stdout")).map_err(|e| e.to_string())?;
    let stderr = fs::read(directory.join("stderr")).map_err(|e| e.to_string())?;
    let parsed = serde_json::from_slice::<Value>(&stdout);
    let outcome = json!({"status":if timeout_hit{"timeout"}else if signal!=0{"signal"}else if exit!=Some(0){"nonzero_exit"}else if parsed.is_err(){"malformed_report"}else{"reported"},
        "wait_status":status,"exit_code":exit,"signal":signal,"timeout":timeout_hit,
        "lifetime_ms":lifetime_ms,"peak_rss_native":usage.max_rss,"rss_units":rss_units,
        "peak_rss_bytes":rss_bytes,"report":parsed.as_ref().ok(),
        "parse_error":parsed.as_ref().err().map(ToString::to_string),"stderr_bytes":stderr.len(),"machine_conditions":machine_conditions()});
    write_json(&directory.join("outcome.json"), &outcome)?;
    if timeout_hit
        || signal != 0
        || exit != Some(0)
        || !stderr.is_empty()
        || rss_bytes == 0
        || parsed.as_ref().is_ok_and(|v| v["status"] != "ok")
    {
        return Err(format!(
            "child failure retained at {}: {outcome}",
            directory.display()
        ));
    }
    Ok(ChildReceipt {
        report: parsed
            .map_err(|e| format!("invalid report retained at {}: {e}", directory.display()))?,
        lifetime_ms,
        rss_bytes,
    })
}

#[derive(Clone)]
struct Workload {
    id: String,
    operation: String,
    data: PathBuf,
    input: PathBuf,
    article: PathBuf,
    fixture_hash: String,
    input_hash: String,
    article_hash: String,
    input_bytes: usize,
    size: usize,
    density: String,
    php: bool,
    metadata: bool,
}
impl Workload {
    fn unchanged(&self) -> Result<(), String> {
        for (path, expected) in [
            (&self.data, &self.fixture_hash),
            (&self.input, &self.input_hash),
            (&self.article, &self.article_hash),
        ] {
            if file_sha256(path)? != *expected {
                return Err(format!("frozen fixture changed: {}", path.display()));
            }
        }
        Ok(())
    }
    fn value(&self) -> Value {
        json!({"id":self.id,"operation":self.operation,"data":self.data,
        "input":self.input,"article":self.article,"size":self.size,"density":self.density,
        "data_sha256":self.fixture_hash,"input_sha256":self.input_hash,
        "article_sha256":self.article_hash,"input_bytes":self.input_bytes,"php":self.php,"metadata":self.metadata})
    }
}
fn payload(data: &[u8], raw: bool) -> Vec<u8> {
    let mut encoded = Vec::new();
    let mut line = Vec::new();
    for byte in data {
        let byte = byte.wrapping_add(42);
        if [0, 10, 13, 61].contains(&byte) {
            line.push(b'=');
            line.push(byte.wrapping_add(64));
        } else {
            line.push(byte);
        }
        if line.len() >= 128 {
            if raw && line[0] == b'.' {
                encoded.push(b'.');
            }
            encoded.extend_from_slice(&line);
            encoded.extend_from_slice(b"\r\n");
            line.clear();
        }
    }
    if !line.is_empty() {
        if raw && line[0] == b'.' {
            encoded.push(b'.');
        }
        encoded.extend_from_slice(&line);
    }
    if raw {
        encoded.extend_from_slice(b"\r\n.\r\n");
    }
    encoded
}
fn fixture_write(directory: &Path, name: &str, bytes: &[u8]) -> Result<PathBuf, String> {
    let path = directory.join(name);
    let mut file = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&path)
        .map_err(|e| e.to_string())?;
    file.write_all(bytes).map_err(|e| e.to_string())?;
    Ok(path)
}
fn workloads(directory: &Path) -> Result<Vec<Workload>, String> {
    workloads_for_sizes(directory, &[4096, 65536, 1048576, 8388608])
}

// Shared with the separate offline evidence verifier; the timed CLI does not use it.
#[allow(dead_code)]
pub(crate) fn evidence_workloads(directory: &Path) -> Result<Vec<Value>, String> {
    workloads(directory).map(|workloads| workloads.iter().map(Workload::value).collect())
}
fn workloads_for_sizes(directory: &Path, sizes: &[usize]) -> Result<Vec<Workload>, String> {
    fs::create_dir(directory).map_err(|e| e.to_string())?;
    let mut workloads = Vec::new();
    for &size in sizes {
        for density in ["ordinary", "heavy"] {
            let id = format!("{density}-{size}");
            let data: Vec<u8> = (0..size)
                .map(|i| {
                    if density == "heavy" {
                        [0xd6, 0xe0, 0xe3, 0x13][i % 4]
                    } else {
                        (i % 256) as u8
                    }
                })
                .collect();
            let encoded = payload(&data, false);
            let raw = payload(&data, true);
            let mut article =
                format!("=ybegin line=128 size={size} name=fixture file.bin\r\n").into_bytes();
            article.extend_from_slice(&encoded);
            if !encoded.ends_with(b"\r\n") {
                article.extend_from_slice(b"\r\n");
            }
            article.extend_from_slice(
                format!("=yend size={size} crc32={:08x}", crc32fast::hash(&data)).as_bytes(),
            );
            let data_path = fixture_write(directory, &format!("{id}.data"), &data)?;
            let input = fixture_write(directory, &format!("{id}.payload"), &encoded)?;
            let raw_path = fixture_write(directory, &format!("{id}.raw"), &raw)?;
            let article_path = fixture_write(directory, &format!("{id}.article"), &article)?;
            for operation in [
                "decode",
                "decode_ex",
                "decode_crc",
                "raw_decode_ex",
                "incremental_raw",
                "crc",
                "crc_combine",
                "crc_zeros",
                "crc_unzero",
                "crc_multiply",
                "crc_2pow",
                "crc_256pow",
                "encode_max_length",
            ] {
                workloads.push(Workload {
                    id: format!("{id}-{operation}"),
                    operation: operation.into(),
                    data: data_path.clone(),
                    input: if operation.starts_with("raw") || operation == "incremental_raw" {
                        raw_path.clone()
                    } else {
                        input.clone()
                    },
                    article: article_path.clone(),
                    fixture_hash: hash(&data),
                    input_hash: hash(
                        if operation.starts_with("raw") || operation == "incremental_raw" {
                            &raw
                        } else {
                            &encoded
                        },
                    ),
                    article_hash: hash(&article),
                    input_bytes: if operation.starts_with("raw") || operation == "incremental_raw" {
                        raw.len()
                    } else {
                        encoded.len()
                    },
                    size,
                    density: density.into(),
                    php: false,
                    metadata: false,
                });
            }
            for path in ["tolerant", "strict", "body"] {
                workloads.push(Workload {
                    id: format!("{id}-php-{path}"),
                    operation: path.into(),
                    data: data_path.clone(),
                    input: input.clone(),
                    article: article_path.clone(),
                    fixture_hash: hash(&data),
                    input_hash: hash(&encoded),
                    article_hash: hash(&article),
                    input_bytes: encoded.len(),
                    size,
                    density: density.into(),
                    php: true,
                    metadata: false,
                });
            }
        }
    }
    for (symbol, bytes) in metadata_fixtures() {
        let path = fixture_write(directory, &format!("{symbol}.data"), &bytes)?;
        workloads.push(Workload {
            id: symbol.clone(),
            operation: symbol,
            data: path.clone(),
            input: path.clone(),
            article: path,
            fixture_hash: hash(&bytes),
            input_hash: hash(&bytes),
            article_hash: hash(&bytes),
            input_bytes: bytes.len(),
            size: bytes.len(),
            density: "metadata".into(),
            php: false,
            metadata: true,
        });
    }
    Ok(workloads)
}

fn put_field(output: &mut Vec<u8>, data: &[u8]) {
    output.extend_from_slice(&(data.len() as u32).to_le_bytes());
    output.extend_from_slice(data);
}
fn metadata_fixtures() -> Vec<(String, Vec<u8>)> {
    use md5::{Digest, Md5};
    let headers=b"Date: Tue, 14 Oct 2025 12:00:00 +0000\r\nInjection-Date: Tue, 14 Oct 2025 12:01:00 +0000\r\nMessage-ID: <fixture@example.test>\r\nLines: 2\r\nXref: news.test alt.binaries.test:123\r\n";
    let body = b"first body line\r\nsecond body line\r\n";
    let article = b"=ybegin line=128 size=3 name=test.bin\r\nklm\r\n=yend size=3 crc32=a3830348";
    let mut pair = Vec::new();
    put_field(&mut pair, headers);
    put_field(&mut pair, headers);
    let mut relation = Vec::new();
    put_field(&mut relation, headers);
    relation.extend_from_slice(body);
    let mut legacy = b"AI01".to_vec();
    put_field(&mut legacy, b"<fixture@example.test>");
    put_field(&mut legacy, headers);
    put_field(
        &mut legacy,
        b"123\tsubject\tposter\tdate\t<fixture@example.test>\t\t34\t2",
    );
    let mut identity = b"AI02".to_vec();
    identity.extend_from_slice(&legacy[4..]);
    put_field(&mut identity, b"221 123 <fixture@example.test>");
    put_field(&mut identity, b"222 123 <fixture@example.test>");
    let mut xref = b"XR01".to_vec();
    put_field(&mut xref, headers);
    let mut rows = 1u32.to_le_bytes().to_vec();
    put_field(&mut rows,b"123\tsubject\tposter\tdate\t<fixture@example.test>\t\t34\t2\tXref: news.test alt.binaries.test:123");
    put_field(&mut xref, &rows);
    let mut format = 8u32.to_le_bytes().to_vec();
    for field in [
        "Subject:",
        "From:",
        "Date:",
        "Message-ID:",
        "References:",
        ":bytes",
        ":lines",
        "Xref:full",
    ] {
        put_field(&mut format, field.as_bytes());
    }
    put_field(&mut xref, &format);
    let mut par2 = b"PAR2\0PKT".to_vec();
    par2.extend_from_slice(&76u64.to_le_bytes());
    par2.extend_from_slice(&[0; 16]);
    par2.extend_from_slice(&[b'S'; 16]);
    let mut kind = [0; 16];
    kind[..13].copy_from_slice(b"PAR 2.0\0Main\0");
    par2.extend_from_slice(&kind);
    par2.extend_from_slice(&4u64.to_le_bytes());
    par2.extend_from_slice(&0u32.to_le_bytes());
    let digest = Md5::digest(&par2[32..]);
    par2[16..32].copy_from_slice(&digest);
    let mut seven = b"7z\xbc\xaf'\x1c\0\x04".to_vec();
    seven.resize(32, 0);
    seven[20..28].copy_from_slice(&1u64.to_le_bytes());
    seven[28..32].copy_from_slice(&crc32fast::hash(&[0]).to_le_bytes());
    let crc = crc32fast::hash(&seven[12..32]);
    seven[8..12].copy_from_slice(&crc.to_le_bytes());
    seven.push(0);
    let mut ranges = 1u32.to_le_bytes().to_vec();
    ranges.extend_from_slice(&(seven.len() as u64).to_le_bytes());
    ranges.extend_from_slice(&0u64.to_le_bytes());
    put_field(&mut ranges, &seven);
    let mut source = b"P2SR".to_vec();
    source.extend_from_slice(&1u32.to_le_bytes());
    source.extend_from_slice(&1u32.to_le_bytes());
    source.extend_from_slice(&3u64.to_le_bytes());
    source.extend_from_slice(&0u64.to_le_bytes());
    put_field(&mut source, b"ABC");
    source.extend_from_slice(&0u32.to_le_bytes());
    source.extend_from_slice(&0u64.to_le_bytes());
    source.extend_from_slice(&3u64.to_le_bytes());
    source.extend_from_slice(&0u64.to_le_bytes());
    source.extend_from_slice(&Md5::digest(b"ABC"));
    source.extend_from_slice(&crc32fast::hash(b"ABC").to_le_bytes());
    vec![
        ("nntmux_par2_metadata_json",par2),("nntmux_format_metadata_json",seven),
        ("nntmux_article_dates_v1_json",headers.to_vec()),("nntmux_seven_zip_ranges_json",ranges),
        ("nntmux_par2_source_ranges_json",source),("nntmux_yenc_framing_metadata_json",article.to_vec()),
        ("nntmux_nntp_header_metadata_json",headers.to_vec()),("nntmux_nntp_header_pair_comparison_json",pair),
        ("nntmux_nntp_body_measurement_json",body.to_vec()),("nntmux_nntp_lines_body_relationship_json",relation),
        ("nntmux_article_identity_v2_json",identity),("nntmux_article_identity_xref_v1_json",xref),
        ("nntmux_article_identity_json",legacy),
        ("nntmux_spot_check_sample_v1_json",br#"{"identity":"fixture","files":[{"key":"fixture","totalparts":3,"segments":[{"number":1,"messageid":"one@test","bytes":100},{"number":2,"messageid":"two@test","bytes":100},{"number":3,"messageid":"three@test","bytes":100}]}]}"#.to_vec()),
        ("nntmux_metadata_string_free",headers.to_vec()),
        ("nntmux_yenc_supports_split_escapes",b"capability".to_vec()),
        ("nntmux_yenc_payload_kernel_v1",b"kernel".to_vec()),
        ("nntmux_yenc_decode_span_v1",b"klm=@=J=M=}..\x0b==".to_vec()),
        ("nntmux_release_names_html",release_names_fixture()),
        ("nntmux_release_names_free",release_names_fixture()),
        (crate::tooling::FINGERPRINT_OPERATION,crate::tooling::fingerprint_fixture())
    ].into_iter().map(|(s,b)|(s.to_owned(),b)).collect()
}

fn release_names_fixture() -> Vec<u8> {
    serde_json::to_vec(&json!({"url_prefix":"/details/","url_suffix":"?x='q'&n=<tag>","releases":[
        {"searchname":r#"[1/2] "Movie & <One>.mkv" yEnc"#,"name":concat!("Original 'One'","\0","& <Name>"),"guid":"safe-guid_01"},
        {"searchname":concat!(r#"[x/2] "Broken <Two>" yEnc"#,"\0","Tail"),"name":concat!(r#"[x/2] "Broken <Two>" yEnc"#,"\0","Tail"),"guid":"safe-guid-02"}
    ]})).expect("constant valid release-name request")
}
fn expected_release_names_html() -> Vec<u8> {
    let first=concat!(r#"<a href="/details/safe-guid_01?x=&#039;q&#039;&amp;n=&lt;tag&gt;" class="text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline" title="Movie &amp; &lt;One&gt;.mkv">Movie &amp; &lt;One&gt;.mkv</a><div class="text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate" title="Original &#039;One&#039;"#,"\0",r#"&amp; &lt;Name&gt;">Original &#039;One&#039;"#,"\0",r#"&amp; &lt;Name&gt;</div>"#).as_bytes();
    let second=concat!(r#"<a href="/details/safe-guid-02?x=&#039;q&#039;&amp;n=&lt;tag&gt;" class="text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline" title="[x/2] &quot;Broken &lt;Two&gt;&quot; yEnc"#,"\0",r#"Tail">[x/2] &quot;Broken &lt;Two&gt;&quot; yEnc"#,"\0","Tail</a>").as_bytes();
    [first, &[0xff], second].concat()
}

type Decode = unsafe extern "C" fn(c_int, *const c_void, *mut c_void, usize, *mut c_int) -> usize;
type PlainDecode = unsafe extern "C" fn(*const c_void, *mut c_void, usize) -> usize;
type Incremental =
    unsafe extern "C" fn(*mut *const c_void, *mut *mut c_void, usize, *mut c_int) -> c_int;
type Crc = unsafe extern "C" fn(*const c_void, usize, u32) -> u32;
type Json = unsafe extern "C" fn(*const u8, usize) -> *mut c_char;
type Free = unsafe extern "C" fn(*mut c_char);
type Html = unsafe extern "C" fn(*const u8, usize, *mut usize) -> *mut u8;
type HtmlFree = unsafe extern "C" fn(*mut u8, usize);
type Number = unsafe extern "C" fn() -> c_int;
type Span =
    unsafe extern "C" fn(*const c_void, usize, usize, usize, *mut c_void, usize, *mut u32) -> usize;
type PairJson = unsafe extern "C" fn(*const u8, usize, c_int) -> *mut c_char;
type LegacyJson = unsafe extern "C" fn(*const u8, usize, c_int, c_int) -> *mut c_char;
type IdentityJson =
    unsafe extern "C" fn(*const u8, usize, c_int, c_int, c_int, c_int) -> *mut c_char;
type XrefJson =
    unsafe extern "C" fn(*const u8, usize, c_int, c_int, c_int, c_int, c_int) -> *mut c_char;
type SampleJson = unsafe extern "C" fn(*const u8, usize, i64, i64, i64, c_int) -> *mut c_char;
type Binary = unsafe extern "C" fn(u32, u32) -> u32;
type Transform = unsafe extern "C" fn(u32, u64) -> u32;
type Combine = unsafe extern "C" fn(u32, u32, u64) -> u32;
type SignedPower = unsafe extern "C" fn(i64) -> u32;
type Power = unsafe extern "C" fn(u64) -> u32;
type Size = unsafe extern "C" fn(usize, c_int) -> usize;

enum NativeOperation {
    Decode(Decode, bool, bool),
    PlainDecode(PlainDecode),
    Incremental(Incremental),
    Crc(Crc),
    Combine(Combine),
    Transform(Transform),
    Binary(Binary),
    SignedPower(SignedPower),
    Power(Power),
    Size(Size),
    Json(Json, Free),
    Html(Html, HtmlFree),
    Fingerprint(crate::tooling::FingerprintApi),
    Pair(PairJson, Free),
    Legacy(LegacyJson, Free),
    Identity(IdentityJson, Free),
    Xref(XrefJson, Free),
    Sample(SampleJson, Free),
    Number(Number),
    Span(Span),
}
impl NativeOperation {
    unsafe fn load(library: &Library, operation: &str) -> Result<Self, String> {
        let free = || unsafe { library.symbol::<Free>("nntmux_metadata_string_free") };
        Ok(match operation {
            "decode" => Self::PlainDecode(unsafe { library.symbol("rapidyenc_decode")? }),
            "decode_ex" | "decode_crc" | "raw_decode_ex" => Self::Decode(
                unsafe { library.symbol("rapidyenc_decode_ex")? },
                operation == "raw_decode_ex",
                operation == "decode_crc",
            ),
            "incremental_raw" => {
                Self::Incremental(unsafe { library.symbol("rapidyenc_decode_incremental")? })
            }
            "crc" => Self::Crc(unsafe { library.symbol("rapidyenc_crc")? }),
            "crc_combine" => Self::Combine(unsafe { library.symbol("rapidyenc_crc_combine")? }),
            "crc_zeros" => Self::Transform(unsafe { library.symbol("rapidyenc_crc_zeros")? }),
            "crc_unzero" => Self::Transform(unsafe { library.symbol("rapidyenc_crc_unzero")? }),
            "crc_multiply" => Self::Binary(unsafe { library.symbol("rapidyenc_crc_multiply")? }),
            "crc_2pow" => Self::SignedPower(unsafe { library.symbol("rapidyenc_crc_2pow")? }),
            "crc_256pow" => Self::Power(unsafe { library.symbol("rapidyenc_crc_256pow")? }),
            "encode_max_length" => {
                Self::Size(unsafe { library.symbol("rapidyenc_encode_max_length")? })
            }
            "nntmux_nntp_header_pair_comparison_json" => {
                Self::Pair(unsafe { library.symbol(operation)? }, free()?)
            }
            "nntmux_article_identity_json" => {
                Self::Legacy(unsafe { library.symbol(operation)? }, free()?)
            }
            "nntmux_article_identity_v2_json" => {
                Self::Identity(unsafe { library.symbol(operation)? }, free()?)
            }
            "nntmux_article_identity_xref_v1_json" => {
                Self::Xref(unsafe { library.symbol(operation)? }, free()?)
            }
            "nntmux_spot_check_sample_v1_json" => {
                Self::Sample(unsafe { library.symbol(operation)? }, free()?)
            }
            "nntmux_metadata_string_free" => Self::Json(
                unsafe { library.symbol("nntmux_nntp_header_metadata_json")? },
                free()?,
            ),
            "nntmux_yenc_supports_split_escapes" | "nntmux_yenc_payload_kernel_v1" => {
                Self::Number(unsafe { library.symbol(operation)? })
            }
            "nntmux_release_names_html" | "nntmux_release_names_free" => Self::Html(
                unsafe { library.symbol("nntmux_release_names_html")? },
                unsafe { library.symbol("nntmux_release_names_free")? },
            ),
            crate::tooling::FINGERPRINT_OPERATION => {
                Self::Fingerprint(unsafe { crate::tooling::FingerprintApi::load(library)? })
            }
            "nntmux_article_fingerprint_add"
            | "nntmux_article_fingerprint_value"
            | "nntmux_article_fingerprint_free" => {
                return Err(format!(
                    "fingerprint lifecycle operation {operation} requires grouped {} workload",
                    crate::tooling::FINGERPRINT_OPERATION
                ));
            }
            "nntmux_yenc_decode_span_v1" => Self::Span(unsafe { library.symbol(operation)? }),
            name if crate::tooling::NNTMUX_EXPORTS.contains(&name) => {
                Self::Json(unsafe { library.symbol(operation)? }, free()?)
            }
            _ => return Err(format!("unknown native operation {operation}")),
        })
    }
}

struct NativeOutput {
    output: Vec<u8>,
    count: usize,
    crc: u32,
    state: c_int,
    consumed: usize,
    end: c_int,
    json: Vec<u8>,
    html: Vec<u8>,
    fingerprint: Vec<u8>,
    number: u64,
}
impl NativeOutput {
    fn new(capacity: usize) -> Self {
        Self {
            output: vec![0xcc; capacity + 64],
            count: 0,
            crc: 0,
            state: 0,
            consumed: 0,
            end: 0,
            json: Vec::new(),
            html: Vec::new(),
            fingerprint: Vec::new(),
            number: 0,
        }
    }
    unsafe fn invoke(
        &mut self,
        operation: &NativeOperation,
        input: &[u8],
        data: &[u8],
        crc: Crc,
        first: u32,
        second: u32,
    ) -> u64 {
        self.count = 0;
        self.crc = 0;
        self.state = 0;
        self.consumed = 0;
        self.end = 0;
        self.number = 0;
        // Required destination initialization is inside both sides' batches.
        // The guard beyond the ABI-permitted capacity is initialized once.
        match operation {
            NativeOperation::Decode(function, raw, with_crc) => {
                self.output[..input.len()].fill(0xcc);
                self.count = unsafe {
                    function(
                        c_int::from(*raw),
                        input.as_ptr().cast(),
                        self.output.as_mut_ptr().cast(),
                        input.len(),
                        std::ptr::null_mut(),
                    )
                };
                if *with_crc && self.count <= input.len() {
                    self.crc = unsafe { crc(self.output.as_ptr().cast(), self.count, 0) };
                }
            }
            NativeOperation::PlainDecode(function) => {
                self.output[..input.len()].fill(0xcc);
                self.count = unsafe {
                    function(
                        input.as_ptr().cast(),
                        self.output.as_mut_ptr().cast(),
                        input.len(),
                    )
                };
            }
            NativeOperation::Incremental(function) => {
                self.output[..input.len()].fill(0xcc);
                let mut source = input.as_ptr().cast::<c_void>();
                let mut destination = self.output.as_mut_ptr().cast::<c_void>();
                while (source as usize).saturating_sub(input.as_ptr() as usize) < input.len() {
                    let consumed = (source as usize).saturating_sub(input.as_ptr() as usize);
                    let length = (input.len() - consumed).min(127);
                    let previous = source;
                    self.end =
                        unsafe { function(&mut source, &mut destination, length, &mut self.state) };
                    if self.end != 0 || source == previous {
                        break;
                    }
                }
                self.consumed = (source as usize).saturating_sub(input.as_ptr() as usize);
                self.count = (destination as usize).saturating_sub(self.output.as_ptr() as usize);
            }
            NativeOperation::Crc(function) => {
                self.crc = unsafe { function(data.as_ptr().cast(), data.len(), 0) }
            }
            NativeOperation::Combine(function) => {
                self.number =
                    unsafe { function(first, second, (data.len() - data.len() / 2) as u64) } as u64
            }
            NativeOperation::Transform(function) => {
                self.number = unsafe { function(first, data.len() as u64) } as u64
            }
            NativeOperation::Binary(function) => {
                self.number = unsafe { function(first, second) } as u64
            }
            NativeOperation::SignedPower(function) => {
                self.number = unsafe { function(-(data.len() as i64)) } as u64
            }
            NativeOperation::Power(function) => {
                self.number = unsafe { function(data.len() as u64) } as u64
            }
            NativeOperation::Size(function) => {
                self.number = unsafe { function(data.len(), 128) } as u64
            }
            NativeOperation::Number(function) => self.number = unsafe { function() } as u64,
            NativeOperation::Span(function) => {
                self.output[..input.len()].fill(0xcc);
                self.count = unsafe {
                    function(
                        input.as_ptr().cast(),
                        input.len(),
                        0,
                        input.len(),
                        self.output.as_mut_ptr().cast(),
                        input.len(),
                        &mut self.crc,
                    )
                };
            }
            NativeOperation::Fingerprint(api) => {
                self.fingerprint = unsafe { crate::tooling::fingerprint_trace(*api, input) };
                self.number = self.fingerprint.len() as u64;
            }
            NativeOperation::Html(render, free) => {
                self.html.clear();
                let mut length = 0usize;
                let pointer = unsafe { render(input.as_ptr(), input.len(), &mut length) };
                self.number = length as u64;
                if !pointer.is_null() {
                    if length <= METADATA_SLOT_LIMIT {
                        self.html.extend_from_slice(unsafe {
                            std::slice::from_raw_parts(pointer, length)
                        });
                    }
                    // Free using the matching loaded-library export and the exact returned length.
                    unsafe { free(pointer, length) };
                }
            }
            operation => {
                let (pointer, free) = match operation {
                    NativeOperation::Json(f, free) => {
                        (unsafe { f(input.as_ptr(), input.len()) }, *free)
                    }
                    NativeOperation::Pair(f, free) => {
                        (unsafe { f(input.as_ptr(), input.len(), 1) }, *free)
                    }
                    NativeOperation::Legacy(f, free) => {
                        (unsafe { f(input.as_ptr(), input.len(), 224, 1) }, *free)
                    }
                    NativeOperation::Identity(f, free) => (
                        unsafe { f(input.as_ptr(), input.len(), 224, 1, 221, 222) },
                        *free,
                    ),
                    NativeOperation::Xref(f, free) => (
                        unsafe { f(input.as_ptr(), input.len(), 221, 224, 1, 1, 215) },
                        *free,
                    ),
                    NativeOperation::Sample(f, free) => (
                        unsafe { f(input.as_ptr(), input.len(), 3, 1000, 200, 1) },
                        *free,
                    ),
                    _ => unreachable!(),
                };
                self.json.clear();
                if !pointer.is_null() {
                    self.json
                        .extend_from_slice(unsafe { CStr::from_ptr(pointer) }.to_bytes());
                    unsafe { free(pointer) };
                }
                self.number = self.json.len() as u64;
            }
        }
        black_box(
            (self.count as u64)
                .wrapping_add((self.crc as u64) << 32)
                .wrapping_add(self.number),
        )
    }
    fn receipt(&self, operation: &str, input: &[u8], data: &[u8]) -> Result<Value, String> {
        if self.count > input.len()
            || self.output[self.output.len() - 64..]
                .iter()
                .any(|b| *b != 0xcc)
        {
            return Err("native returned invalid extent or destroyed capacity canary".into());
        }
        if [
            "decode",
            "decode_ex",
            "decode_crc",
            "raw_decode_ex",
            "incremental_raw",
        ]
        .contains(&operation)
            && (self.count != data.len() || self.output[..self.count] != *data)
        {
            return Err("native decoded bytes incorrect".into());
        }
        if ["crc", "decode_crc"].contains(&operation) && self.crc != crc32fast::hash(data) {
            return Err("native CRC incorrect".into());
        }
        let metadata = if operation.ends_with("_json") || operation == "nntmux_metadata_string_free"
        {
            if self.json.is_empty() {
                return Err("native metadata returned null/empty".into());
            }
            Some(
                serde_json::from_slice::<Value>(&self.json)
                    .map_err(|e| format!("native metadata malformed: {e}"))?,
            )
        } else {
            None
        };
        if operation == "nntmux_yenc_decode_span_v1" {
            let expected = b"ABC\xd6\xe0\xe3\x13\x04\x04\xe1\xd3";
            if self.output[..self.count] != *expected || self.crc != crc32fast::hash(expected) {
                return Err("native span output incorrect".into());
            }
        }
        let html = if html_operation(operation) {
            if self.html.is_empty() || self.html.len() as u64 != self.number {
                return Err("native HTML returned null, invalid length or oversized output".into());
            }
            if input == release_names_fixture() && self.html != expected_release_names_html() {
                return Err("native HTML differs from exact independent fixture bytes".into());
            }
            Some(
                json!({"sha256":hash(&self.html),"length":self.html.len(),"delimiter":255,
                "delimiter_offsets":self.html.iter().enumerate().filter_map(|(i,b)|(*b==0xff).then_some(i)).collect::<Vec<_>>(),
                "nul_offsets":self.html.iter().enumerate().filter_map(|(i,b)|(*b==0).then_some(i)).collect::<Vec<_>>()}),
            )
        } else {
            None
        };
        if operation == crate::tooling::FINGERPRINT_OPERATION
            && (input != crate::tooling::fingerprint_fixture()
                || self.fingerprint != crate::tooling::expected_fingerprint_trace()
                || self.number != self.fingerprint.len() as u64)
        {
            return Err("native fingerprint lifecycle status/preimage/canary mismatch".into());
        }
        // Implementation capabilities/kernel numbers intentionally differ from
        // the incumbent; preserve actual observations separately, verify shape.
        let diagnostic = operation == "nntmux_yenc_supports_split_escapes"
            || operation == "nntmux_yenc_payload_kernel_v1";
        if operation == "nntmux_yenc_supports_split_escapes" && self.number > 1 {
            return Err("capability not boolean".into());
        }
        let mut receipt = json!({"output_sha256":hash(&self.output[..self.count]),"count":self.count,"crc":self.crc,
            "state":self.state,"consumed":self.consumed,"end":self.end,"metadata":metadata,"metadata_raw_sha256":if metadata.is_some(){Some(hash(&self.json))}else{None},
            "number":if diagnostic{None}else{Some(self.number)},"diagnostic":diagnostic,"html":html});
        if operation == crate::tooling::FINGERPRINT_OPERATION {
            receipt["fingerprint"] = json!({"encoding":"little-endian i32 statuses; guarded 40-byte big-endian preimages; owned free count", "bytes":self.fingerprint,"sha256":hash(&self.fingerprint)});
        }
        Ok(receipt)
    }
}

#[allow(dead_code)]
pub(crate) fn expected_fingerprint_receipt() -> Value {
    let trace = crate::tooling::expected_fingerprint_trace();
    json!({"output_sha256":hash(&[]),"count":0,"crc":0,"state":0,"consumed":0,"end":0,
        "metadata":null,"metadata_raw_sha256":null,"number":trace.len(),"diagnostic":false,"html":null,
        "fingerprint":{"encoding":"little-endian i32 statuses; guarded 40-byte big-endian preimages; owned free count","sha256":hash(&trace),"bytes":trace}})
}

/// One batch in an isolated process. Preflight checks happen before its timer;
/// output validation/receipt hashing happens after it, including frozen parity.
pub fn worker(options: &Options) -> Result<Value, String> {
    let library_path = Path::new(required(options, "--library")?);
    let artifact_hash = file_sha256(library_path)?;
    if artifact_hash != required(options, "--library-sha256")? {
        return Err("worker library identity changed".into());
    }
    let data = fs::read(required(options, "--data")?).map_err(|e| e.to_string())?;
    let input = fs::read(required(options, "--input")?).map_err(|e| e.to_string())?;
    if hash(&input) != required(options, "--input-sha256")?
        || hash(&data) != required(options, "--data-sha256")?
    {
        return Err("worker frozen fixture changed".into());
    }
    let operation = required(options, "--operation")?;
    if operation == crate::tooling::FINGERPRINT_OPERATION
        && input != crate::tooling::fingerprint_fixture()
    {
        return Err("fingerprint input differs from frozen bounded fixture".into());
    }
    let iterations: usize = required(options, "--iterations")?
        .parse()
        .map_err(|_| "invalid iterations")?;
    if iterations == 0 || iterations > 1_000_000_000 {
        return Err("batch count outside safety bounds".into());
    }
    let library = Library::open(library_path)?;
    if library.sha256 != artifact_hash {
        return Err("loaded private library snapshot differs from selected artifact".into());
    }
    type Init = unsafe extern "C" fn();
    let decode_init: Init = unsafe { library.symbol("rapidyenc_decode_init")? };
    let crc_init: Init = unsafe { library.symbol("rapidyenc_crc_init")? };
    let crc: Crc = unsafe { library.symbol("rapidyenc_crc")? };
    unsafe {
        decode_init();
        crc_init();
    }
    let function = unsafe { NativeOperation::load(&library, operation)? };
    let first = crc32fast::hash(&data[..data.len() / 2]);
    let second = crc32fast::hash(&data[data.len() / 2..]);
    let mut output = NativeOutput::new(if requires_byte_witness(&function) {
        input.len()
    } else {
        0
    });
    unsafe { output.invoke(&function, &input, &data, crc, first, second) };
    let preflight = output
        .receipt(operation, &input, &data)
        .map_err(|error| native_failure(&error, &output))?;
    let expected = match (options.get("--expected"), options.get("--expected-sha256")) {
        (None, None) => None,
        (Some(path), Some(digest)) => Some(FrozenJson::supplied(path, digest)?),
        _ => return Err("expected receipt needs frozen path and digest".into()),
    };
    if expected
        .as_ref()
        .map(FrozenJson::read)
        .transpose()?
        .as_ref()
        .is_some_and(|value| value != &preflight)
    {
        return Err("native frozen preflight parity failed".into());
    }
    if options
        .get("--baseline-role")
        .is_some_and(|role| role == "cpp")
    {
        let decoder: Number = unsafe { library.symbol("rapidyenc_decode_kernel")? };
        let crc_kernel: Number = unsafe { library.symbol("rapidyenc_crc_kernel")? };
        validate_reference_dispatch(unsafe { decoder() }, unsafe { crc_kernel() })?;
    }
    let batch = run_batch(
        &mut output,
        &function,
        operation,
        &input,
        &data,
        crc,
        first,
        second,
        iterations,
        &preflight,
    )?;
    let receipt = output.receipt(operation, &input, &data)?;
    if expected
        .as_ref()
        .map(FrozenJson::read)
        .transpose()?
        .as_ref()
        .is_some_and(|value| value != &receipt)
    {
        return Err("expected receipt changed during batch".into());
    }
    let version: Number = unsafe { library.symbol("rapidyenc_version")? };
    let diagnostic = |symbol: &str| {
        unsafe { library.symbol::<Number>(symbol) }
            .ok()
            .map(|f| unsafe { f() })
    };
    library.unchanged()?;
    if file_sha256(library_path)? != artifact_hash {
        return Err("worker library changed during execution".into());
    }
    Ok(
        json!({"status":"ok","operation":operation,"iterations":iterations,"elapsed_ms":batch.elapsed_ms,
        "library_sha256":artifact_hash,"input_sha256":hash(&input),"data_sha256":hash(&data),
        "receipt":receipt,"expected_sha256":expected.as_ref().map(|f|&f.sha256),"batch_results":{"encoding":"one run; every scalar or byte/state witness checked","value":batch.result_value,"count":batch.verified},
        "timed_chunks":batch.chunks,"chunk_slots":batch.slots,"chunk_memory_limit":CHUNK_MEMORY_LIMIT,"maximum_chunk_slots":MAX_CHUNK_SLOTS,"scalar_chunk_slots":SCALAR_CHUNK_SLOTS,
        "diagnostic_value":output.number,"version":unsafe{version()},
        "decoder_kernel":diagnostic("rapidyenc_decode_kernel"),"crc_kernel":diagnostic("rapidyenc_crc_kernel"),
        "os":std::env::consts::OS,"arch":std::env::consts::ARCH}),
    )
}

#[derive(Clone)]
struct Artifact {
    name: String,
    path: PathBuf,
    hash: String,
}
impl Artifact {
    fn freeze(name: &str, path: &str) -> Result<Self, String> {
        let path = Path::new(path).canonicalize().map_err(|e| e.to_string())?;
        Ok(Self {
            name: name.into(),
            hash: file_sha256(&path)?,
            path,
        })
    }
    fn unchanged(&self) -> Result<(), String> {
        if file_sha256(&self.path)? != self.hash {
            Err(format!("{} artifact changed", self.name))
        } else {
            Ok(())
        }
    }
    fn value(&self) -> Value {
        json!({"name":self.name,"path":self.path,"sha256":self.hash})
    }
}
struct Executor {
    executable: Artifact,
    php: Artifact,
    php_worker: Artifact,
    root: PathBuf,
    next: usize,
    evidence: Vec<FrozenJson>,
}
impl Executor {
    fn fresh_replay(&mut self, candidate: &Artifact, corpus: &FrozenJson) -> Result<Value, String> {
        candidate.unchanged()?;
        self.executable.unchanged()?;
        for evidence in &self.evidence {
            evidence.unchanged()?;
        }
        let directory = self.root.join(format!(
            "{:06}-preflight-full-correctness-replay",
            self.next
        ));
        self.next += 1;
        let mut command = Command::new(&self.executable.path);
        command
            .arg("check")
            .arg("--candidate")
            .arg(&candidate.path)
            .arg("--receipts")
            .arg(&corpus.path);
        let child = capture_fresh(
            &mut command,
            &self.executable.path,
            &directory,
            CHILD_TIMEOUT,
        )?;
        validate_replay_report(&child.report, &candidate.hash, &corpus.sha256)?;
        candidate.unchanged()?;
        for evidence in &self.evidence {
            evidence.unchanged()?;
        }
        Ok(
            json!({"report":child.report,"lifetime_ms":child.lifetime_ms,"peak_rss_bytes":child.rss_bytes,"child_directory":directory}),
        )
    }
    fn native(
        &mut self,
        artifact: &Artifact,
        work: &Workload,
        iterations: usize,
        expected: Option<&FrozenJson>,
        phase: &str,
    ) -> Result<ChildReceipt, String> {
        let mut command = Command::new(&self.executable.path);
        command.arg("bench-worker");
        for (key, value) in [
            ("--library", artifact.path.to_string_lossy().into_owned()),
            ("--library-sha256", artifact.hash.clone()),
            ("--operation", work.operation.clone()),
            ("--data", work.data.to_string_lossy().into_owned()),
            ("--input", work.input.to_string_lossy().into_owned()),
            ("--iterations", iterations.to_string()),
            ("--data-sha256", work.fixture_hash.clone()),
            ("--input-sha256", work.input_hash.clone()),
        ] {
            command.arg(key).arg(value);
        }
        if let Some(path) = expected {
            path.read()?;
            command
                .arg("--expected")
                .arg(&path.path)
                .arg("--expected-sha256")
                .arg(&path.sha256);
        }
        if artifact.name == "cpp" {
            command.arg("--baseline-role").arg("cpp");
        }
        let child = self.execute(&mut command, artifact, work, phase)?;
        if child.report["status"] != "ok"
            || child.report["library_sha256"] != artifact.hash
            || child.report["operation"] != work.operation
            || child.report["iterations"].as_u64() != Some(iterations as u64)
            || child.report["input_sha256"] != work.input_hash
            || child.report["data_sha256"] != work.fixture_hash
            || !child.report["receipt"].is_object()
            || child.report["batch_results"]["count"].as_u64() != Some(iterations as u64)
        {
            return Err("invalid native report, raw child evidence retained".into());
        }
        positive(&child.report, "elapsed_ms")?;
        let retain_bytes = operation_needs_bytes(&work.operation);
        let metadata = allocated_metadata(&work.operation);
        let slots = chunk_slots(work.input_bytes, metadata, retain_bytes)?;
        let chunks = iterations.div_ceil(slots);
        if child.report["chunk_slots"].as_u64() != Some(slots as u64)
            || child.report["timed_chunks"].as_u64() != Some(chunks as u64)
            || child.report["chunk_memory_limit"].as_u64() != Some(CHUNK_MEMORY_LIMIT as u64)
            || child.report["maximum_chunk_slots"].as_u64() != Some(MAX_CHUNK_SLOTS as u64)
            || child.report["scalar_chunk_slots"].as_u64() != Some(SCALAR_CHUNK_SLOTS as u64)
            || artifact.name == "cpp"
                && (child.report["decoder_kernel"] != 4096 || child.report["crc_kernel"] != 72)
        {
            return Err("native chunk protocol or accelerated reference dispatch mismatch".into());
        }
        if let Some(path) = expected
            && (child.report["receipt"] != path.read()?
                || child.report["expected_sha256"] != path.sha256)
        {
            return Err("native output differs from frozen receipt".into());
        }
        Ok(child)
    }
    fn php(
        &mut self,
        artifact: &Artifact,
        work: &Workload,
        phase: &str,
    ) -> Result<ChildReceipt, String> {
        let mut command = Command::new(&self.php.path);
        command
            .arg(&self.php_worker.path)
            .arg("--worker=native")
            .arg("--samples=3")
            .arg(format!("--native={}", artifact.path.display()))
            .arg(format!("--path={}", work.operation))
            .arg(format!("--data-path={}", work.data.display()))
            .arg(format!("--article-path={}", work.article.display()));
        let child = self.execute(&mut command, artifact, work, phase)?;
        validate_php(&child.report, &artifact.hash, &work.fixture_hash, work.size)?;
        let reported = positive(&child.report, "peak_rss_bytes")?;
        // /proc VmHWM and wait4 may differ by page-accounting or startup. Record
        // both; reject a greater-than-10% discrepancy as measurement ineligibility.
        if (reported - child.rss_bytes as f64).abs() / (child.rss_bytes as f64) > 0.10 {
            return Err("PHP RSS and wait4 disagree by over 10%; retained run ineligible".into());
        }
        Ok(child)
    }
    fn execute(
        &mut self,
        command: &mut Command,
        artifact: &Artifact,
        work: &Workload,
        phase: &str,
    ) -> Result<ChildReceipt, String> {
        artifact.unchanged()?;
        for evidence in &self.evidence {
            evidence.unchanged()?;
        }
        self.executable.unchanged()?;
        self.php.unchanged()?;
        self.php_worker.unchanged()?;
        work.unchanged()?;
        let directory = self.root.join(format!(
            "{:06}-{}-{}-{}",
            self.next, phase, artifact.name, work.id
        ));
        self.next += 1;
        let child = capture_fresh(command, &self.executable.path, &directory, CHILD_TIMEOUT)?;
        artifact.unchanged()?;
        Ok(child)
    }
}

fn shuffle<T>(values: &mut [T], state: &mut u32) {
    for i in (1..values.len()).rev() {
        *state ^= *state << 13;
        *state ^= *state >> 17;
        *state ^= *state << 5;
        values.swap(i, *state as usize % (i + 1));
    }
}
fn incumbent_supported(work: &Workload) -> bool {
    work.php
        || work.metadata
        || ["decode_ex", "decode_crc", "crc"].contains(&work.operation.as_str())
}
struct Comparison {
    work: Workload,
    baseline: Artifact,
    iterations: usize,
    expected: Option<FrozenJson>,
    order: Vec<[String; 2]>,
}
impl Comparison {
    fn value(&self) -> Value {
        json!({"workload":self.work.value(),"baseline":self.baseline.value(),"iterations":self.iterations,
        "expected_receipt":self.expected.as_ref().map(|f|&f.path),"expected_receipt_sha256":self.expected.as_ref().map(|f|&f.sha256),
        "chunk_slots":chunk_slots(self.work.input_bytes,allocated_metadata(&self.work.operation),operation_needs_bytes(&self.work.operation)).ok(),
        "timed_chunks_per_observation":chunk_slots(self.work.input_bytes,allocated_metadata(&self.work.operation),operation_needs_bytes(&self.work.operation)).ok().map(|slots|self.iterations.div_ceil(slots)),
        "variant_order":self.order})
    }
}
fn summarize_variant(
    children: &[ChildReceipt],
    work: &Workload,
    artifact: &Artifact,
) -> Result<Value, String> {
    if children.len() != OBSERVATIONS {
        return Err("eleven measured child observations required".into());
    }
    let mut times = Vec::new();
    let mut lifetimes = Vec::new();
    let mut rss = 0;
    for child in children {
        if work.php {
            times.extend(validate_php(
                &child.report,
                &artifact.hash,
                &work.fixture_hash,
                work.size,
            )?);
        } else {
            let elapsed = positive(&child.report, "elapsed_ms")?;
            if elapsed < MIN_BATCH_MS {
                return Err(
                    "kernel observation below frozen 250ms floor; reversion protocol required"
                        .into(),
                );
            }
            times.push(elapsed);
        }
        lifetimes.push(child.lifetime_ms);
        rss = rss.max(child.rss_bytes);
    }
    let timed = summarize(&times)?;
    let lifecycle = summarize(&lifetimes)?;
    Ok(
        json!({"median_ms":timed.median,"p95_ms":timed.p95,"peak_rss_bytes":rss,
        "lifecycle_median_ms":lifecycle.median,"lifecycle_p95_ms":lifecycle.p95,
        "timed_observations_ms":times,"lifecycle_observations_ms":lifetimes,
        "children":children.iter().map(|child|json!({"lifetime_ms":child.lifetime_ms,"peak_rss_bytes":child.rss_bytes,"report":child.report})).collect::<Vec<_>>(),
        "nested_calls":work.php,"ordered":!work.php || children.iter().all(|c|c.report.get("observations").is_some())}),
    )
}
pub(crate) fn ratio(candidate: &Value, baseline: &Value) -> Result<Value, String> {
    Ok(
        json!({"median_ratio":positive(candidate,"median_ms")?/positive(baseline,"median_ms")?,
        "p95_ratio":positive(candidate,"p95_ms")?/positive(baseline,"p95_ms")?,
        "rss_ratio":positive(candidate,"peak_rss_bytes")?/positive(baseline,"peak_rss_bytes")?,
        "lifecycle_median_ratio":positive(candidate,"lifecycle_median_ms")?/positive(baseline,"lifecycle_median_ms")?,
        "lifecycle_p95_ratio":positive(candidate,"lifecycle_p95_ms")?/positive(baseline,"lifecycle_p95_ms")?}),
    )
}

/// Full fixed protocol. No retries are attempted: failures are retained and stop
/// this candidate. A harness/environment repair requires a new output directory.
pub fn run(options: &Options) -> Result<Value, String> {
    if !cfg!(target_os = "linux") {
        return Err("primary PHP qualification requires owned disposable Linux runtime".into());
    }
    let output = PathBuf::from(required(options, "--output-dir")?);
    fs::create_dir(&output).map_err(|e| format!("new output directory required: {e}"))?;
    let result = run_owned(options, &output);
    match &result {
        Ok(report) => write_json(&output.join("result.json"), report)?,
        Err(error) => write_json(
            &output.join("failure.json"),
            &json!({"status":"failed","error":error,
            "protocol":PROTOCOL,"no_retries":true,"all_children_retained":true}),
        )?,
    }
    result
}
fn run_owned(options: &Options, output: &Path) -> Result<Value, String> {
    let candidate = Artifact::freeze("candidate", required(options, "--candidate")?)?;
    let reference = Artifact::freeze("cpp", required(options, "--reference")?)?;
    let incumbent = Artifact::freeze("incumbent", required(options, "--incumbent")?)?;
    if [reference.hash.as_str(), incumbent.hash.as_str()].contains(&candidate.hash.as_str()) {
        return Err("candidate must have distinct frozen artifact identity".into());
    }
    let runtime_path = Path::new(required(options, "--runtime-manifest")?);
    let runtime_frozen = FrozenJson::freeze(runtime_path)?;
    let runtime = runtime_frozen.read()?;
    for field in [
        "sources",
        "lockfile",
        "compilers",
        "image_ids",
        "build_flags",
        "php",
        "extensions",
        "vendor",
        "cpu",
        "machine_conditions",
        "correctness",
    ] {
        if runtime.get(field).is_none_or(Value::is_null) {
            return Err(format!("runtime manifest missing {field}"));
        }
    }
    if runtime["correctness"]["status"] != "passed" {
        return Err("full correctness proof must precede performance".into());
    }
    for artifact in [&candidate, &reference, &incumbent] {
        if runtime["artifacts"][&artifact.name]["sha256"] != artifact.hash {
            return Err(format!("runtime manifest {} hash mismatch", artifact.name));
        }
    }
    let evidence_field =
        |section: &str, path_key: &str, hash_key: &str| -> Result<FrozenJson, String> {
            FrozenJson::supplied(
                runtime[section][path_key]
                    .as_str()
                    .ok_or_else(|| format!("missing {section}.{path_key}"))?,
                runtime[section][hash_key]
                    .as_str()
                    .ok_or_else(|| format!("missing {section}.{hash_key}"))?,
            )
        };
    let corpus = evidence_field("correctness", "receipts_path", "receipt_sha256")?;
    validate_trusted_corpus(&corpus)?;
    let replay = evidence_field("correctness", "report_path", "report_sha256")?;
    validate_replay_report(&replay.read()?, &candidate.hash, &corpus.sha256)?;
    let build = evidence_field("reference", "build_manifest_path", "build_manifest_sha256")?;
    validate_reference_manifest(&build.read()?, &reference.hash)?;
    let evidence = vec![runtime_frozen, corpus.clone(), replay, build];
    let executable = Artifact::freeze(
        "executor",
        &std::env::current_exe()
            .map_err(|e| e.to_string())?
            .to_string_lossy(),
    )?;
    let php = Artifact::freeze("php", required(options, "--php")?)?;
    let php_worker = Artifact::freeze("php_worker", required(options, "--worker")?)?;
    fs::create_dir(output.join("children")).map_err(|e| e.to_string())?;
    fs::create_dir(output.join("receipts")).map_err(|e| e.to_string())?;
    let workloads = workloads(&output.join("fixtures"))?;
    let mut executor = Executor {
        executable,
        php,
        php_worker,
        root: output.join("children"),
        next: 0,
        evidence,
    };
    let fresh_replay = executor.fresh_replay(&candidate, &corpus)?;
    write_json(&output.join("fresh-correctness-replay.json"), &fresh_replay)?;
    let initial = json!({"protocol":PROTOCOL,"runtime_manifest":runtime,"runtime_manifest_sha256":executor.evidence[0].sha256,"fresh_correctness_replay":fresh_replay,
        "evidence":executor.evidence.iter().map(|f|json!({"path":f.path,"sha256":f.sha256})).collect::<Vec<_>>(),
        "artifacts":[candidate.value(),reference.value(),incumbent.value()],"executor":executor.executable.value(),
        "php":executor.php.value(),"php_worker":executor.php_worker.value(),"os":std::env::consts::OS,"arch":std::env::consts::ARCH,
        "seed":SEED,"repetitions":REPETITIONS,"excluded_outer_warmups":WARMUPS,"observations":OBSERVATIONS,
        "php_internal_warmups":1,"php_samples":3,"minimum_kernel_batch_ms":MIN_BATCH_MS,"timeout_seconds":CHILD_TIMEOUT.as_secs(),
        "retry_policy":"hard fail every error; no retries", "maximum_scheduled_pairs":5,
        "batch_results":"every scalar witness or every distinct intermediate byte/state witness verified with timer stopped",
        "chunk_memory_limit":CHUNK_MEMORY_LIMIT,"maximum_chunk_slots":MAX_CHUNK_SLOTS,"scalar_chunk_slots":SCALAR_CHUNK_SLOTS,"metadata_slot_limit":METADATA_SLOT_LIMIT,"elapsed_clock":"sum of timed chunk intervals; verification excluded",
        "fixtures":workloads.iter().map(Workload::value).collect::<Vec<_>>()});
    write_json(&output.join("identity.json"), &initial)?;
    let mut comparisons = Vec::new();
    let mut random = SEED;
    for baseline in [&reference, &incumbent] {
        for work in &workloads {
            if baseline.name == "cpp" && work.metadata
                || baseline.name == "incumbent" && !incumbent_supported(work)
            {
                continue;
            }
            let expected = if work.php {
                None
            } else {
                let child = executor.native(baseline, work, 1, None, "receipt-freeze")?;
                let path = output
                    .join("receipts")
                    .join(format!("{}-{}.json", baseline.name, work.id));
                write_json(&path, &child.report["receipt"])?;
                let frozen = FrozenJson::freeze(&path)?;
                executor.native(&candidate, work, 1, Some(&frozen), "receipt-replay")?;
                Some(frozen)
            };
            // Calibration is explicitly preflight, then frozen before any paired
            // observations. Both variants get the identical final iteration count.
            let mut iterations = 1usize;
            if !work.php {
                loop {
                    let baseline_probe = executor.native(
                        baseline,
                        work,
                        iterations,
                        expected.as_ref(),
                        "calibration",
                    )?;
                    let candidate_probe = executor.native(
                        &candidate,
                        work,
                        iterations,
                        expected.as_ref(),
                        "calibration",
                    )?;
                    if positive(&baseline_probe.report, "elapsed_ms")? >= 500.
                        && positive(&candidate_probe.report, "elapsed_ms")? >= 500.
                    {
                        break;
                    }
                    iterations = iterations
                        .checked_mul(2)
                        .filter(|n| *n <= 1_000_000_000)
                        .ok_or("unable to calibrate batches; protocol needs revision")?;
                }
            }
            let mut order = Vec::new();
            for _ in 0..REPETITIONS {
                let mut pair = ["candidate".into(), baseline.name.clone()];
                shuffle(&mut pair, &mut random);
                order.push(pair);
            }
            comparisons.push(Comparison {
                work: work.clone(),
                baseline: baseline.clone(),
                iterations,
                expected,
                order,
            });
        }
    }
    let mut schedule: Vec<(usize, usize)> = (0..comparisons.len())
        .flat_map(|comparison| (0..REPETITIONS).map(move |repetition| (comparison, repetition)))
        .collect();
    shuffle(&mut schedule, &mut random);
    let protocol = json!({"identity_sha256":file_sha256(&output.join("identity.json"))?,
        "comparisons":comparisons.iter().map(Comparison::value).collect::<Vec<_>>(),"schedule":schedule,
        "acceptance":{"cpp_large_decode_crc":0.90,"cpp_large_php":0.95,"required_faster_pairs":4,"remaining_pair":1.0,
        "other_medians":1.05,"all_p95":1.05,"all_maximum_peak_rss":1.05,"cold_lifecycle_median_p95":1.05}});
    write_json(&output.join("protocol.json"), &protocol)?;
    let protocol_hash = file_sha256(&output.join("protocol.json"))?;
    let mut pairs: BTreeMap<usize, Vec<Value>> = BTreeMap::new();
    for (index, repetition) in &schedule {
        let comparison = &comparisons[*index];
        let work = &comparison.work;
        let mut variants = BTreeMap::new();
        for name in &comparison.order[*repetition] {
            let artifact = if name == "candidate" {
                &candidate
            } else {
                &comparison.baseline
            };
            for observation in 0..WARMUPS {
                let phase = format!("pair{repetition}-warmup{observation}");
                if work.php {
                    executor.php(artifact, work, &phase)?;
                } else {
                    executor.native(
                        artifact,
                        work,
                        comparison.iterations,
                        comparison.expected.as_ref(),
                        &phase,
                    )?;
                }
            }
            let mut children = Vec::new();
            for observation in 0..OBSERVATIONS {
                let phase = format!("pair{repetition}-sample{observation}");
                let child = if work.php {
                    executor.php(artifact, work, &phase)?
                } else {
                    executor.native(
                        artifact,
                        work,
                        comparison.iterations,
                        comparison.expected.as_ref(),
                        &phase,
                    )?
                };
                children.push(child);
            }
            variants.insert(name.clone(), summarize_variant(&children, work, artifact)?);
        }
        let candidate_variant = &variants["candidate"];
        let baseline_variant = &variants[&comparison.baseline.name];
        let ratios = ratio(candidate_variant, baseline_variant)?;
        let pair = json!({"comparison":index,"repetition":repetition,"workload":work.id,"baseline":comparison.baseline.name,
            "variant_order":comparison.order[*repetition],"candidate":candidate_variant,"reference":baseline_variant,"ratios":ratios});
        write_json(
            &output.join(format!("pair-{index}-{repetition}.json")),
            &pair,
        )?;
        pairs.entry(*index).or_default().push(pair);
        if file_sha256(&output.join("protocol.json"))? != protocol_hash {
            return Err("frozen protocol mutated".into());
        }
    }
    let mut gates = Vec::new();
    let mut passed = true;
    for (index, comparison) in comparisons.iter().enumerate() {
        let work = &comparison.work;
        let pair_receipts = pairs.get(&index).ok_or("missing comparison pairs")?;
        let ratios: Vec<Value> = pair_receipts.iter().map(|p| p["ratios"].clone()).collect();
        let large = work.size >= 1048576 && comparison.baseline.name == "cpp";
        let improvement = if large && work.php {
            Some(0.95)
        } else if large && work.operation == "decode_crc" {
            Some(0.90)
        } else {
            None
        };
        let timed_pass = decision(&ratios, improvement)?;
        let lifecycle_pass = !work.php
            || ratios.iter().all(|r| {
                r["lifecycle_median_ratio"]
                    .as_f64()
                    .is_some_and(|v| v <= 1.05)
                    && r["lifecycle_p95_ratio"].as_f64().is_some_and(|v| v <= 1.05)
            });
        passed &= timed_pass && lifecycle_pass;
        gates.push(json!({"workload":work.id,"baseline":comparison.baseline.name,"primary_pass":timed_pass,
            "cold_lifecycle_pass":lifecycle_pass,"required_speed_ratio":improvement,"pair_ratios":ratios}));
    }
    for artifact in [&candidate, &reference, &incumbent] {
        artifact.unchanged()?;
    }
    for evidence in &executor.evidence {
        evidence.unchanged()?;
    }
    let report = json!({"status":if passed{"passed"}else{"failed_gates"},"protocol":PROTOCOL,"protocol_sha256":protocol_hash,
        "all_pairs":pairs,"gates":gates,"architecture_scope":std::env::consts::ARCH,"output_directory":output});
    if !passed {
        write_json(&output.join("gate-failure.json"), &report)?;
        return Err("predeclared performance gates failed; retained gate-failure.json".into());
    }
    Ok(report)
}
#[derive(Clone)]
struct FrozenJson {
    path: PathBuf,
    sha256: String,
}
impl FrozenJson {
    fn freeze(path: &Path) -> Result<Self, String> {
        Ok(Self {
            path: path.into(),
            sha256: file_sha256(path)?,
        })
    }
    fn read(&self) -> Result<Value, String> {
        let bytes = fs::read(&self.path).map_err(|e| e.to_string())?;
        if hash(&bytes) != self.sha256 {
            return Err(format!(
                "frozen JSON digest changed: {}",
                self.path.display()
            ));
        }
        serde_json::from_slice(&bytes).map_err(|e| e.to_string())
    }
    fn unchanged(&self) -> Result<(), String> {
        if file_sha256(&self.path)? != self.sha256 {
            return Err(format!(
                "frozen evidence digest changed: {}",
                self.path.display()
            ));
        }
        Ok(())
    }
    fn supplied(path: &str, digest: &str) -> Result<Self, String> {
        if digest.len() != 64 || !digest.bytes().all(|b| b.is_ascii_hexdigit()) {
            return Err("invalid frozen SHA256".into());
        }
        let frozen = Self {
            path: PathBuf::from(path),
            sha256: digest.into(),
        };
        frozen.read()?;
        Ok(frozen)
    }
}
fn validate_trusted_corpus(corpus: &FrozenJson) -> Result<(), String> {
    if corpus.sha256 != crate::abi_receipts::FROZEN_RECEIPT_SHA256 {
        return Err("correctness corpus differs from trusted full replay corpus".into());
    }
    corpus.unchanged()
}
fn validate_reference_dispatch(decoder: i32, crc: i32) -> Result<(), String> {
    if decoder != 4096 || crc != 72 {
        return Err("reference dispatch is not frozen NEON / ARM-CRC + PMULL".into());
    }
    Ok(())
}
pub(crate) fn validate_replay_report(
    report: &Value,
    candidate: &str,
    receipts: &str,
) -> Result<(), String> {
    if report["status"] != "ok"
        || report["artifact"]["sha256"] != candidate
        || report["receipt_sha256"] != receipts
        || report["calls"].as_u64() != Some(crate::abi_receipts::FROZEN_REPLAY_CALLS as u64)
        || report["artifact"]["rapidyenc_exports"] != json!(crate::tooling::RAPIDYENC_EXPORTS)
        || report["artifact"]["metadata_exports"] != json!(crate::tooling::NNTMUX_EXPORTS)
    {
        return Err(
            "full replay proof does not bind candidate, trusted receipts, all calls and exports"
                .into(),
        );
    }
    Ok(())
}
pub(crate) fn validate_reference_manifest(report: &Value, reference: &str) -> Result<(), String> {
    if report["library_sha256"] != reference
        || report["source_commit"] != "480bd7b5896f8b3edecc721d23f1384d767ffe2f"
        || report["source_archive_sha256"]
            != "bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16"
        || report["build_native"] != false
        || report["disable_encode"] != true
        || report["disable_tool"] != true
        || report["platform"] != "linux/arm64"
        || report["public_rapidyenc_export_count"] != 16
        || report["runtime_kernel_selection"]["decoder"] != "NEON"
        || report["runtime_kernel_selection"]["crc32"] != "ARM-CRC + PMULL"
    {
        return Err("reference build proof differs from frozen accelerated ARM64 build".into());
    }
    Ok(())
}

struct BatchObservation {
    elapsed_ms: f64,
    result_value: u64,
    chunks: usize,
    slots: usize,
    verified: usize,
}
fn native_failure(message: &str, output: &NativeOutput) -> String {
    format!(
        "{message}; native_failure_receipt={}",
        json!({"count":output.count,"crc":output.crc,"state":output.state,
        "consumed":output.consumed,"end":output.end,"number":output.number,"destination_with_canaries":output.output,
        "raw_metadata":output.json,"raw_html":output.html,"raw_fingerprint":output.fingerprint})
    )
}
fn result_value(output: &NativeOutput) -> u64 {
    (output.count as u64)
        .wrapping_add((output.crc as u64) << 32)
        .wrapping_add(output.number)
}
fn html_operation(operation: &str) -> bool {
    matches!(
        operation,
        "nntmux_release_names_html" | "nntmux_release_names_free"
    )
}
pub(crate) fn allocated_metadata(operation: &str) -> bool {
    operation.ends_with("_json")
        || operation == "nntmux_metadata_string_free"
        || html_operation(operation)
        || operation == crate::tooling::FINGERPRINT_OPERATION
}
pub(crate) fn operation_needs_bytes(operation: &str) -> bool {
    [
        "decode",
        "decode_ex",
        "decode_crc",
        "raw_decode_ex",
        "incremental_raw",
        "nntmux_yenc_decode_span_v1",
        "nntmux_metadata_string_free",
    ]
    .contains(&operation)
        || operation.ends_with("_json")
        || html_operation(operation)
        || operation == crate::tooling::FINGERPRINT_OPERATION
}
fn requires_byte_witness(function: &NativeOperation) -> bool {
    matches!(
        function,
        NativeOperation::Decode(..)
            | NativeOperation::PlainDecode(..)
            | NativeOperation::Incremental(..)
            | NativeOperation::Span(..)
            | NativeOperation::Html(..)
            | NativeOperation::Fingerprint(..)
            | NativeOperation::Json(..)
            | NativeOperation::Pair(..)
            | NativeOperation::Legacy(..)
            | NativeOperation::Identity(..)
            | NativeOperation::Xref(..)
            | NativeOperation::Sample(..)
    )
}
pub(crate) fn chunk_slots(
    input_length: usize,
    metadata: bool,
    retain_bytes: bool,
) -> Result<usize, String> {
    if !retain_bytes {
        return Ok(SCALAR_CHUNK_SLOTS);
    }
    let bytes = input_length
        .checked_add(64)
        .and_then(|n| n.checked_add(if metadata { METADATA_SLOT_LIMIT } else { 0 }))
        .ok_or("chunk budget overflow")?;
    if bytes > CHUNK_MEMORY_LIMIT {
        return Err("fixture exceeds frozen per-chunk memory budget".into());
    }
    Ok((CHUNK_MEMORY_LIMIT / bytes).min(MAX_CHUNK_SLOTS))
}
#[derive(Clone, Copy, PartialEq, Eq)]
struct NativeFields {
    count: usize,
    crc: u32,
    state: c_int,
    consumed: usize,
    end: c_int,
    number: u64,
}
impl NativeFields {
    fn observe(output: &NativeOutput) -> Self {
        Self {
            count: output.count,
            crc: output.crc,
            state: output.state,
            consumed: output.consumed,
            end: output.end,
            number: output.number,
        }
    }
}
fn intact_canary(output: &NativeOutput) -> bool {
    output.output[output.output.len() - 64..]
        .iter()
        .all(|byte| *byte == 0xcc)
}
// These bytes are borrowed from the independently checked and frozen-bound
// preflight output. Equality of every later witness proves the same receipt,
// without rehashing, reparsing or recomputing independent CRC per invocation.
struct VerifiedByteWitness<'a> {
    fields: NativeFields,
    decoded: &'a [u8],
    metadata: &'a [u8],
    html: &'a [u8],
    fingerprint: &'a [u8],
}
impl<'a> VerifiedByteWitness<'a> {
    fn capture(output: &'a NativeOutput) -> Self {
        Self {
            fields: NativeFields::observe(output),
            decoded: &output.output[..output.count],
            metadata: &output.json,
            html: &output.html,
            fingerprint: &output.fingerprint,
        }
    }
    fn matches(&self, output: &NativeOutput) -> bool {
        NativeFields::observe(output) == self.fields
            && intact_canary(output)
            && output.output[..output.count] == *self.decoded
            && output.json == self.metadata
            && output.html == self.html
            && output.fingerprint == self.fingerprint
    }
}
#[allow(clippy::too_many_arguments)]
fn run_batch(
    output: &mut NativeOutput,
    function: &NativeOperation,
    operation: &str,
    input: &[u8],
    data: &[u8],
    crc: Crc,
    first: u32,
    second: u32,
    iterations: usize,
    preflight: &Value,
) -> Result<BatchObservation, String> {
    // Do not turn an unchecked candidate result into its own oracle. The full
    // independent/frozen receipt is proved once before taking direct witnesses.
    if output.receipt(operation, input, data)? != *preflight {
        return Err(native_failure("unverified preflight witness", output));
    }
    let expected_scalar = result_value(output);
    let expected_fields = NativeFields::observe(output);
    let retains_bytes = requires_byte_witness(function);
    let metadata = allocated_metadata(operation);
    let slot_count = chunk_slots(input.len(), metadata, retains_bytes)?;
    if !retains_bytes {
        let mut results = vec![0u64; SCALAR_CHUNK_SLOTS];
        let mut elapsed = Duration::ZERO;
        let mut verified = 0;
        let mut chunks = 0;
        while verified < iterations {
            let count = (iterations - verified).min(SCALAR_CHUNK_SLOTS);
            let start = Instant::now();
            for result in &mut results[..count] {
                *result = unsafe { output.invoke(function, input, data, crc, first, second) };
            }
            elapsed += start.elapsed();
            chunks += 1;
            if results[..count]
                .iter()
                .any(|value| *value != expected_scalar)
                || NativeFields::observe(output) != expected_fields
                || !intact_canary(output)
            {
                return Err(format!(
                    "{}; scalar_chunk={}",
                    native_failure("intermediate scalar witness mismatch", output),
                    json!({"start":verified,"expected":expected_scalar,"witnesses":&results[..count]})
                ));
            }
            verified += count;
        }
        return Ok(BatchObservation {
            elapsed_ms: elapsed.as_secs_f64() * 1000.,
            result_value: expected_scalar,
            chunks,
            slots: SCALAR_CHUNK_SLOTS,
            verified,
        });
    }
    if output.json.len() + output.html.len() + output.fingerprint.len() > METADATA_SLOT_LIMIT {
        return Err("metadata exceeds frozen witness budget".into());
    }
    let witness = VerifiedByteWitness::capture(output);
    // Each invocation has distinct ABI destination storage until its bytes and
    // state are verified. No verification-only output copy is timed.
    let mut slots = (0..slot_count)
        .map(|_| {
            let mut slot = NativeOutput::new(input.len());
            slot.json.reserve(output.json.len());
            slot.html.reserve(output.html.len());
            slot.fingerprint.reserve(output.fingerprint.len());
            slot
        })
        .collect::<Vec<_>>();
    let mut elapsed = Duration::ZERO;
    let mut verified = 0;
    let mut chunks = 0;
    while verified < iterations {
        let count = (iterations - verified).min(slot_count);
        let start = Instant::now();
        for slot in &mut slots[..count] {
            unsafe { slot.invoke(function, input, data, crc, first, second) };
        }
        elapsed += start.elapsed();
        chunks += 1;
        // This entire check occurs with the kernel timer stopped, before any
        // slot is overwritten by the next timed chunk.
        for slot in &slots[..count] {
            if slot.json.len() + slot.html.len() + slot.fingerprint.len() > METADATA_SLOT_LIMIT
                || !witness.matches(slot)
            {
                return Err(native_failure(
                    &format!(
                        "intermediate byte/state witness mismatch after {verified} verified operations"
                    ),
                    slot,
                ));
            }
        }
        verified += count;
    }
    Ok(BatchObservation {
        elapsed_ms: elapsed.as_secs_f64() * 1000.,
        result_value: expected_scalar,
        chunks,
        slots: slot_count,
        verified,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    fn report() -> Value {
        json!({"status":"ok", "library_sha256":"artifact", "decoder_version":"native",
        "min_ms":1., "median_ms":2., "max_ms":3., "peak_rss_bytes":1000,
        "observations":(-1..3).map(|sample|json!({"sample":sample,"warmup":sample<0,
        "elapsed_ms":if sample<0 {4.}else{sample as f64+1.},"correct":true,
        "decoded_bytes":4,"decoded_sha256":"fixture"})).collect::<Vec<_>>()})
    }
    #[test]
    fn malformed_or_incorrect_php_cannot_qualify() {
        let mut r = report();
        r["status"] = json!("incorrect");
        assert!(validate_php(&r, "artifact", "fixture", 4).is_err());
        let mut r = report();
        r["library_sha256"] = json!("wrong");
        assert!(validate_php(&r, "artifact", "fixture", 4).is_err());
        let mut r = report();
        r["observations"][1]["decoded_sha256"] = json!("wrong");
        assert!(validate_php(&r, "artifact", "fixture", 4).is_err());
        let mut r = report();
        r["median_ms"] = json!(20.);
        assert!(validate_php(&r, "artifact", "fixture", 4).is_err());
    }
    #[test]
    fn actual_order_is_preserved() {
        let mut r = report();
        for (i, v) in [3., 1., 2.].iter().enumerate() {
            r["observations"][i + 1]["elapsed_ms"] = json!(v);
        }
        assert_eq!(
            validate_php(&r, "artifact", "fixture", 4).unwrap(),
            vec![3., 1., 2.]
        );
    }
    #[test]
    fn large_speed_gate_needs_four_pairs_and_no_other_regression() {
        let pair =
            |median, p95, rss| json!({"median_ratio":median,"p95_ratio":p95,"rss_ratio":rss});
        let pairs = vec![
            pair(0.9, 1., 1.),
            pair(0.9, 1., 1.),
            pair(0.9, 1., 1.),
            pair(0.91, 1., 1.),
            pair(1., 1., 1.),
        ];
        assert!(!decision(&pairs, Some(0.9)).unwrap());
        let mut pairs = vec![pair(0.9, 1., 1.); 5];
        pairs[4] = pair(1.01, 1., 1.);
        assert!(!decision(&pairs, Some(0.9)).unwrap());
        let mut pairs = vec![pair(0.9, 1., 1.); 5];
        pairs[0] = pair(0.9, 1.06, 1.);
        assert!(!decision(&pairs, Some(0.9)).unwrap());
    }
    fn temporary(name: &str) -> PathBuf {
        static NEXT: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        let path = std::env::temp_dir().join(format!(
            "nntmux-bench-{name}-{}-{}",
            std::process::id(),
            NEXT.fetch_add(1, std::sync::atomic::Ordering::Relaxed)
        ));
        fs::create_dir(&path).unwrap();
        path
    }
    #[test]
    fn zero_exit_incorrect_report_is_retained_and_fails() {
        let path = temporary("incorrect");
        let mut command = Command::new("/bin/sh");
        command.arg("-c").arg("printf '{\"status\":\"incorrect\"}'");
        let result = capture(&mut command, &path.join("child"), Duration::from_secs(2));
        assert!(result.is_err());
        assert_eq!(
            read_json(&path.join("child/outcome.json")).unwrap()["report"]["status"],
            "incorrect"
        );
        assert!(path.join("child/stdout").is_file());
        assert!(path.join("child/stderr").is_file());
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    fn crash_timeout_and_malformed_report_keep_raw_evidence() {
        for (name, script, timeout, expected_stdout, expected_status) in [
            (
                "crash",
                "printf before-crash; kill -SEGV $$",
                2000,
                "before-crash",
                "signal",
            ),
            ("silent-timeout", "sleep 3", 20, "", "timeout"),
            (
                "emitting-timeout",
                "printf before-timeout; sleep 3",
                2000,
                "before-timeout",
                "timeout",
            ),
            (
                "malformed",
                "printf invalid-json",
                2000,
                "invalid-json",
                "malformed_report",
            ),
        ] {
            let path = temporary(name);
            let mut command = Command::new("/bin/sh");
            command.arg("-c").arg(script);
            assert!(
                capture(
                    &mut command,
                    &path.join("child"),
                    Duration::from_millis(timeout)
                )
                .is_err()
            );
            assert_eq!(
                fs::read(path.join("child/stdout")).unwrap(),
                expected_stdout.as_bytes()
            );
            assert!(path.join("child/stderr").is_file());
            let outcome = read_json(&path.join("child/outcome.json")).unwrap();
            assert_eq!(outcome["status"], expected_status);
            fs::remove_dir_all(path).unwrap();
        }
    }
    #[test]
    #[cfg(target_os = "linux")]
    #[ignore = "build and select NNTMUX_BENCH_TEST_EXECUTOR; Linux causal measurement"]
    fn resident_parent_does_not_inflate_worker_peak_rss() {
        let mut resident = vec![0u8; 128 * 1024 * 1024];
        for page in resident.chunks_mut(4096) {
            page[0] = 42;
        }
        black_box(&resident);
        let path = temporary("resident-parent");
        let mut command = Command::new("/bin/sh");
        // A modest resident child allocation makes kernel page-accounting
        // differences small relative to the child's HWM. It is still far below
        // the 128MiB inherited parent floor that this regression catches.
        command.arg("-c").arg(r#"payload=x; for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21; do payload=$payload$payload; done; while read key value unit; do if [ "$key" = "VmHWM:" ]; then printf '{"status":"ok","peak_rss_bytes":%s}' "$((value * 1024))"; fi; done < /proc/self/status"#);
        let direct = capture(&mut command, &path.join("direct"), Duration::from_secs(2)).unwrap();
        assert!(direct.rss_bytes > direct.report["peak_rss_bytes"].as_u64().unwrap() * 2);
        let executable =
            std::env::var("NNTMUX_BENCH_TEST_EXECUTOR").expect("build and select real qualifier");
        let child = capture_fresh(
            &mut command,
            Path::new(&executable),
            &path.join("child"),
            Duration::from_secs(2),
        )
        .unwrap();
        let worker_peak = child.report["peak_rss_bytes"].as_u64().unwrap();
        println!(
            "RSS causal witness direct_wait4={} direct_VmHWM={} fresh_wait4={} fresh_VmHWM={}",
            direct.rss_bytes, direct.report["peak_rss_bytes"], child.rss_bytes, worker_peak
        );
        assert!(
            (child.rss_bytes as f64 - worker_peak as f64).abs() / worker_peak as f64 <= 0.10,
            "wait4={} VmHWM={}",
            child.rss_bytes,
            worker_peak
        );
        black_box(&resident);
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    #[cfg(target_os = "linux")]
    #[ignore = "build and select NNTMUX_BENCH_TEST_EXECUTOR; real collector process"]
    fn fresh_collector_retains_failures_and_reaps_worker_descendants() {
        let executable = std::env::var("NNTMUX_BENCH_TEST_EXECUTOR").unwrap();
        for (name, script, timeout, status) in [
            (
                "crash",
                "printf before-crash; kill -SEGV $$",
                2000,
                "signal",
            ),
            (
                "timeout",
                "sleep 30 & echo $! > \"$PID_PATH\"; printf before-timeout; wait",
                30,
                "timeout",
            ),
            ("malformed", "printf invalid-json", 2000, "malformed_report"),
            (
                "incorrect",
                "printf '{\"status\":\"incorrect\"}'",
                2000,
                "reported",
            ),
            (
                "collector-killed",
                "sleep 30 & echo $! > \"$PID_PATH\"; printf before-broker-death; kill -KILL $PPID; wait",
                2000,
                "",
            ),
        ] {
            let path = temporary(name);
            let marker = path.join("descendant.pid");
            let mut command = Command::new("/bin/sh");
            command.arg("-c").arg(script).env("PID_PATH", &marker);
            assert!(
                capture_fresh(
                    &mut command,
                    Path::new(&executable),
                    &path.join("child"),
                    Duration::from_millis(timeout)
                )
                .is_err(),
                "{name}"
            );
            assert!(path.join("child/collector/outcome.json").is_file());
            assert!(path.join("child/collector.json").is_file());
            assert!(
                !fs::read(path.join("child/stdout")).unwrap().is_empty(),
                "{name}"
            );
            if !status.is_empty() {
                assert_eq!(
                    read_json(&path.join("child/outcome.json")).unwrap()["status"],
                    status
                );
            }
            if marker.is_file() {
                let pid = fs::read_to_string(marker)
                    .unwrap()
                    .trim()
                    .parse::<c_int>()
                    .unwrap();
                assert_eq!(
                    unsafe { kill(pid, 0) },
                    -1,
                    "leaked {name} descendant {pid}"
                );
                assert_eq!(std::io::Error::last_os_error().raw_os_error(), Some(3));
            }
            fs::remove_dir_all(path).unwrap();
        }
        let path = temporary("fresh-spawn-failure");
        let mut command = Command::new("/does-not-exist/nntmux-worker");
        assert!(
            capture_fresh(
                &mut command,
                Path::new(&executable),
                &path.join("child"),
                Duration::from_secs(2)
            )
            .is_err()
        );
        assert_eq!(
            read_json(&path.join("child/outcome.json")).unwrap()["status"],
            "spawn_failed"
        );
        assert!(path.join("child/stdout").is_file());
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    #[cfg(target_os = "linux")]
    #[ignore = "build and select NNTMUX_BENCH_TEST_EXECUTOR; real collector process"]
    fn fresh_collector_startup_is_excluded_from_worker_lifetime() {
        use std::os::unix::fs::PermissionsExt;
        let executable = std::env::var("NNTMUX_BENCH_TEST_EXECUTOR").unwrap();
        let path = temporary("collector-startup");
        let wrapper = path.join("slow-collector");
        fs::write(
            &wrapper,
            format!(
                "#!/bin/sh\nsleep 0.2\nexec '{}' \"$@\"\n",
                executable.replace("'", "'\\''")
            ),
        )
        .unwrap();
        fs::set_permissions(&wrapper, fs::Permissions::from_mode(0o700)).unwrap();
        let mut command = Command::new("/bin/sh");
        command.arg("-c").arg("printf '{\"status\":\"ok\"}'");
        let start = Instant::now();
        let child = capture_fresh(
            &mut command,
            &wrapper,
            &path.join("child"),
            Duration::from_secs(2),
        )
        .unwrap();
        let overall = start.elapsed().as_secs_f64() * 1000.;
        assert!(overall - child.lifetime_ms >= 190.);
        assert!(child.lifetime_ms < 100.);
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    #[cfg(target_os = "linux")]
    #[ignore = "select real qualifier, capture request and retained receipt root"]
    fn fresh_collector_real_worker_wait4_agrees_with_proc_vm_hwm() {
        let executable = std::env::var("NNTMUX_BENCH_TEST_EXECUTOR").unwrap();
        let request = json_file(Path::new(
            &std::env::var("NNTMUX_BENCH_TEST_CAPTURE_REQUEST").unwrap(),
        ))
        .unwrap();
        let path = PathBuf::from(std::env::var("NNTMUX_BENCH_TEST_RECEIPT_ROOT").unwrap());
        let mut resident = vec![0u8; 128 * 1024 * 1024];
        for page in resident.chunks_mut(4096) {
            page[0] = 42;
        }
        black_box(&resident);
        let mut command = Command::new(request["program"].as_str().unwrap());
        for argument in request["arguments"].as_array().unwrap() {
            command.arg(argument.as_str().unwrap());
        }
        if let Some(cwd) = request["cwd"].as_str() {
            command.current_dir(cwd);
        }
        for (key, value) in request["environment"].as_object().unwrap() {
            if value.is_null() {
                command.env_remove(key);
            } else {
                command.env(key, value.as_str().unwrap());
            }
        }
        let timeout = Duration::from_millis(request["timeout_ms"].as_u64().unwrap());
        let (child, proc_peak) = std::thread::scope(|scope| {
            let job =
                scope.spawn(|| capture_fresh(&mut command, Path::new(&executable), &path, timeout));
            let mut proc_peak = 0u64;
            while !job.is_finished() {
                if let Ok(pid) = fs::read_to_string(path.join("worker.pid"))
                    && let Ok(status) = fs::read_to_string(format!("/proc/{pid}/status"))
                {
                    for line in status.lines() {
                        if let Some(value) = line.strip_prefix("VmHWM:") {
                            proc_peak = proc_peak.max(
                                value
                                    .split_whitespace()
                                    .next()
                                    .unwrap()
                                    .parse::<u64>()
                                    .unwrap()
                                    * 1024,
                            );
                        }
                    }
                }
                std::thread::sleep(Duration::from_micros(100));
            }
            (job.join().unwrap().unwrap(), proc_peak)
        });
        assert!(proc_peak > 0, "worker was too brief to sample VmHWM");
        assert!(
            (child.rss_bytes as f64 - proc_peak as f64).abs() / child.rss_bytes as f64 <= 0.10,
            "wait4={} sampledVmHWM={}",
            child.rss_bytes,
            proc_peak
        );
        if child.report["peak_rss_bytes"].is_number() {
            let reported = positive(&child.report, "peak_rss_bytes").unwrap();
            assert!((reported - child.rss_bytes as f64).abs() / child.rss_bytes as f64 <= 0.10);
        }
        let diagnostic = json!({"status":"ok","wait4_peak_rss_bytes":child.rss_bytes,
            "sampled_proc_vm_hwm_bytes":proc_peak,"worker_lifetime_ms":child.lifetime_ms,
            "parent_touched_bytes":resident.len(),"scope":"bounded real-worker RSS diagnostic; no speed qualification"});
        println!("{diagnostic}");
        write_json(&path.join("rss-comparison.json"), &diagnostic).unwrap();
        black_box(&resident);
    }
    #[test]
    fn per_child_rss_uses_explicit_os_units() {
        let path = temporary("rss");
        let mut command = Command::new("/bin/sh");
        command.arg("-c").arg("printf '{\"status\":\"ok\"}'");
        let child = capture(&mut command, &path.join("child"), Duration::from_secs(2)).unwrap();
        assert!(child.rss_bytes > 0);
        assert!(child.lifetime_ms > 0.);
        let outcome = read_json(&path.join("child/outcome.json")).unwrap();
        let multiplier = if cfg!(target_os = "macos") { 1 } else { 1024 };
        assert_eq!(
            child.rss_bytes,
            outcome["peak_rss_native"].as_u64().unwrap() * multiplier
        );
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    fn exact_statistics_and_deterministic_order_are_fixed() {
        let times = (1..=33).rev().map(f64::from).collect::<Vec<_>>();
        let stats = summarize(&times).unwrap();
        assert_eq!((stats.median, stats.p95), (17., 32.));
        let stats = summarize(&(1..=11).map(f64::from).collect::<Vec<_>>()).unwrap();
        assert_eq!((stats.median, stats.p95), (6., 11.));
        let mut first = (0..40).collect::<Vec<_>>();
        let mut second = first.clone();
        shuffle(&mut first, &mut SEED.clone());
        shuffle(&mut second, &mut SEED.clone());
        assert_eq!(first, second);
        assert_ne!(first, (0..40).collect::<Vec<_>>());
    }
    #[test]
    #[ignore = "explicit artifact required: NNTMUX_BENCH_TEST_LIBRARY and NNTMUX_BENCH_TEST_INCUMBENT; build cdylib first"]
    fn native_workers_invoke_real_fixture_exports() {
        let library =
            std::env::var("NNTMUX_BENCH_TEST_LIBRARY").expect("select candidate artifact");
        let incumbent =
            std::env::var("NNTMUX_BENCH_TEST_INCUMBENT").expect("select incumbent artifact");
        let path = temporary("native");
        let fixtures = workloads_for_sizes(&path.join("fixtures"), &[4096]).unwrap();
        // One complete payload size and all metadata fixtures. All sizes are
        // frozen by the full protocol; this is a bounded harness behavior test.
        for work in fixtures
            .iter()
            .filter(|w| !w.php && (w.size == 4096 || w.metadata))
        {
            let options = |selected: &str| {
                BTreeMap::from([
                    ("--library".into(), selected.into()),
                    (
                        "--library-sha256".into(),
                        file_sha256(Path::new(selected)).unwrap(),
                    ),
                    ("--data".into(), work.data.to_string_lossy().into_owned()),
                    ("--input".into(), work.input.to_string_lossy().into_owned()),
                    ("--data-sha256".into(), work.fixture_hash.clone()),
                    ("--input-sha256".into(), file_sha256(&work.input).unwrap()),
                    ("--operation".into(), work.operation.clone()),
                    ("--iterations".into(), "2".into()),
                ])
            };
            let result = worker(&options(&library)).unwrap_or_else(|e| panic!("{}: {e}", work.id));
            if incumbent_supported(work) {
                let baseline = worker(&options(&incumbent))
                    .unwrap_or_else(|e| panic!("incumbent {}: {e}", work.id));
                assert_eq!(result["receipt"], baseline["receipt"], "{}", work.id);
            }
            let mut wrong = options(&library);
            wrong.insert("--library-sha256".into(), "incorrect".into());
            assert!(worker(&wrong).is_err());
            let receipt = path.join(format!("{}.json", work.id));
            write_json(&receipt, &json!({"wrong":true})).unwrap();
            let mut wrong = options(&library);
            wrong.insert("--expected".into(), receipt.to_string_lossy().into_owned());
            wrong.insert("--expected-sha256".into(), file_sha256(&receipt).unwrap());
            assert!(worker(&wrong).is_err());
        }
        fs::remove_dir_all(path).unwrap();
    }

    #[test]
    fn fixture_mutation_makes_the_frozen_workload_ineligible() {
        let directory = temporary("mutation");
        let data = fixture_write(&directory, "data", b"ABC").unwrap();
        let input = fixture_write(&directory, "input", b"klm").unwrap();
        let article = fixture_write(&directory, "article", b"article").unwrap();
        let work = Workload {
            id: "fixture".into(),
            operation: "decode_ex".into(),
            fixture_hash: hash(b"ABC"),
            data,
            input,
            article,
            input_hash: hash(b"klm"),
            article_hash: hash(b"article"),
            input_bytes: 3,
            size: 3,
            density: "ordinary".into(),
            php: false,
            metadata: false,
        };
        assert!(work.unchanged().is_ok());
        fs::write(&work.input, b"xyz").unwrap();
        assert!(work.unchanged().is_err());
        fs::remove_dir_all(directory).unwrap();
    }
    #[test]
    fn grouped_php_receipts_keep_thirty_three_calls_and_eleven_lifetimes() {
        let artifact = Artifact {
            name: "candidate".into(),
            path: PathBuf::new(),
            hash: "artifact".into(),
        };
        let work = Workload {
            id: "php".into(),
            operation: "strict".into(),
            data: PathBuf::new(),
            input: PathBuf::new(),
            article: PathBuf::new(),
            fixture_hash: "fixture".into(),
            input_hash: String::new(),
            article_hash: String::new(),
            input_bytes: 0,
            size: 4,
            density: "ordinary".into(),
            php: true,
            metadata: false,
        };
        let mut children = Vec::new();
        for i in 0..11 {
            let mut r = report();
            let a = i as f64 * 3. + 1.;
            r["min_ms"] = json!(a);
            r["median_ms"] = json!(a + 1.);
            r["max_ms"] = json!(a + 2.);
            for (j, time) in [a + 2., a, a + 1.].iter().enumerate() {
                r["observations"][j + 1]["elapsed_ms"] = json!(time);
            }
            children.push(ChildReceipt {
                report: r,
                lifetime_ms: i as f64 + 1.,
                rss_bytes: 1000 + i * 100,
            });
        }
        let result = summarize_variant(&children, &work, &artifact).unwrap();
        assert_eq!(result["median_ms"], 17.);
        assert_eq!(result["p95_ms"], 32.);
        assert_eq!(result["lifecycle_median_ms"], 6.);
        assert_eq!(result["lifecycle_p95_ms"], 11.);
        assert_eq!(result["peak_rss_bytes"], 2000);
        assert_eq!(
            result["timed_observations_ms"].as_array().unwrap().len(),
            33
        );
        assert_eq!(result["children"].as_array().unwrap().len(), 11);
        assert_eq!(
            &result["timed_observations_ms"].as_array().unwrap()[..3],
            &[json!(3.), json!(1.), json!(2.)]
        );
    }
    #[test]
    fn improvement_and_regression_threshold_boundaries_are_inclusive() {
        let pair = |median| json!({"median_ratio":median,"p95_ratio":1.05,"rss_ratio":1.05});
        let mut pairs = vec![pair(0.9); 5];
        pairs[4] = pair(1.);
        assert!(decision(&pairs, Some(0.9)).unwrap());
        assert!(decision(&vec![pair(1.05); 5], None).unwrap());
        assert!(decision(&pairs[..4], Some(0.9)).is_err());
        assert!(!decision(&vec![pair(1.05001); 5], None).unwrap());
    }
    #[test]
    fn child_receipts_record_machine_conditions_before_and_after() {
        let path = temporary("conditions");
        let mut command = Command::new("/bin/sh");
        command.arg("-c").arg("printf '{\"status\":\"ok\"}'");
        capture(&mut command, &path.join("child"), Duration::from_secs(2)).unwrap();
        let before = read_json(&path.join("child/command.json")).unwrap();
        let after = read_json(&path.join("child/outcome.json")).unwrap();
        for receipt in [before, after] {
            assert_eq!(
                receipt["machine_conditions"]["arch"],
                std::env::consts::ARCH
            );
            assert!(
                receipt["machine_conditions"]["unix_time_ms"]
                    .as_u64()
                    .is_some()
            );
        }
        fs::remove_dir_all(path).unwrap();
    }
    #[test]
    #[ignore = "explicit CLI and candidate artifacts required: NNTMUX_BENCH_TEST_EXECUTABLE and NNTMUX_BENCH_TEST_LIBRARY"]
    fn real_cli_workers_keep_success_and_identity_failure_receipts() {
        let program =
            std::env::var("NNTMUX_BENCH_TEST_EXECUTABLE").expect("select qualification CLI");
        let library = std::env::var("NNTMUX_BENCH_TEST_LIBRARY").expect("select native artifact");
        let root = temporary("actual-cli");
        let data = fixture_write(&root, "data", b"ABC").unwrap();
        let input = fixture_write(&root, "input", b"klm").unwrap();
        for (name, artifact_hash, success) in [
            ("correct", file_sha256(Path::new(&library)).unwrap(), true),
            ("wrong-artifact", "wrong".into(), false),
        ] {
            let mut command = Command::new(&program);
            command.arg("bench-worker");
            for (key, value) in [
                ("--library", library.clone()),
                ("--library-sha256", artifact_hash),
                ("--data", data.to_string_lossy().into_owned()),
                ("--input", input.to_string_lossy().into_owned()),
                ("--data-sha256", hash(b"ABC")),
                ("--input-sha256", hash(b"klm")),
                ("--operation", "decode_ex".into()),
                ("--iterations", "2".into()),
            ] {
                command.arg(key).arg(value);
            }
            let child = capture(&mut command, &root.join(name), Duration::from_secs(5));
            assert_eq!(child.is_ok(), success);
            if let Ok(child) = child {
                assert_eq!(child.report["receipt"]["output_sha256"], hash(b"ABC"));
                assert_eq!(child.report["batch_results"]["count"], 2);
            }
            assert!(root.join(name).join("stdout").is_file());
            assert!(root.join(name).join("stderr").is_file());
            assert!(root.join(name).join("outcome.json").is_file());
        }
        fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn changed_frozen_receipt_cannot_redefine_correctness() {
        let root = temporary("receipt-mutation");
        let path = root.join("receipt.json");
        write_json(&path, &json!({"output":"original"})).unwrap();
        let frozen = FrozenJson::freeze(&path).unwrap();
        assert!(frozen.read().is_ok());
        fs::write(&path, b"{\"output\":\"changed\"}").unwrap();
        assert!(frozen.read().is_err());
        fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn abbreviated_unbound_or_wrong_coverage_replay_cannot_qualify() {
        let report = json!({"status":"ok","artifact":{"sha256":"candidate","rapidyenc_exports":crate::tooling::RAPIDYENC_EXPORTS,
            "metadata_exports":crate::tooling::NNTMUX_EXPORTS},"receipt_sha256":"trusted","calls":crate::abi_receipts::FROZEN_REPLAY_CALLS});
        assert!(validate_replay_report(&report, "candidate", "trusted").is_ok());
        for (key, value) in [
            ("calls", json!(0)),
            ("receipt_sha256", json!("wrong")),
            ("status", json!("incorrect")),
            ("artifact", json!({"sha256":"other"})),
        ] {
            let mut invalid = report.clone();
            invalid[key] = value;
            assert!(
                validate_replay_report(&invalid, "candidate", "trusted").is_err(),
                "{key}"
            );
        }
        let mut invalid = report.clone();
        invalid["artifact"]["rapidyenc_exports"] = json!(["rapidyenc_decode_ex"]);
        assert!(validate_replay_report(&invalid, "candidate", "trusted").is_err());
    }
    #[test]
    fn scalar_or_misbound_reference_manifest_cannot_qualify() {
        let report = json!({"source_commit":"480bd7b5896f8b3edecc721d23f1384d767ffe2f",
            "source_archive_sha256":"bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16",
            "library_sha256":"reference","build_native":false,"disable_encode":true,"disable_tool":true,
            "platform":"linux/arm64","public_rapidyenc_export_count":16,"runtime_kernel_selection":{"decoder":"NEON","crc32":"ARM-CRC + PMULL"}});
        assert!(validate_reference_manifest(&report, "reference").is_ok());
        let mut invalid = report.clone();
        invalid["runtime_kernel_selection"]["decoder"] = json!("scalar");
        assert!(validate_reference_manifest(&invalid, "reference").is_err());
        let mut invalid = report.clone();
        invalid["library_sha256"] = json!("other");
        assert!(validate_reference_manifest(&invalid, "reference").is_err());
    }
    #[test]
    fn unverified_preflight_output_cannot_seed_a_witness_or_invoke_the_library() {
        static CALLS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn decode(
            _: c_int,
            _: *const c_void,
            destination: *mut c_void,
            _: usize,
            _: *mut c_int,
        ) -> usize {
            CALLS.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            unsafe { std::ptr::copy_nonoverlapping(b"ABC".as_ptr(), destination.cast(), 3) };
            3
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        let mut verified = NativeOutput::new(3);
        verified.output[..3].copy_from_slice(b"ABC");
        verified.count = 3;
        let preflight = verified.receipt("decode_ex", b"klm", b"ABC").unwrap();
        let mut unverified = NativeOutput::new(3);
        unverified.output[..3].copy_from_slice(b"XBC");
        unverified.count = 3;
        CALLS.store(0, std::sync::atomic::Ordering::SeqCst);
        assert!(
            run_batch(
                &mut unverified,
                &NativeOperation::Decode(decode, false, false),
                "decode_ex",
                b"klm",
                b"ABC",
                unused_crc,
                0,
                0,
                1,
                &preflight
            )
            .is_err()
        );
        assert_eq!(CALLS.load(std::sync::atomic::Ordering::SeqCst), 0);
    }
    #[test]
    fn incorrect_intermediate_state_is_rejected_with_identical_output_bytes() {
        static CALLS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn decode(
            source: *mut *const c_void,
            destination: *mut *mut c_void,
            _: usize,
            state: *mut c_int,
        ) -> c_int {
            unsafe {
                std::ptr::copy_nonoverlapping(b"ABC".as_ptr(), (*destination).cast(), 3);
                *source = (*source).cast::<u8>().add(3).cast();
                *destination = (*destination).cast::<u8>().add(3).cast();
                *state = if CALLS.fetch_add(1, std::sync::atomic::Ordering::SeqCst) == 2 {
                    1
                } else {
                    0
                };
            }
            0
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        let mut output = NativeOutput::new(3);
        output.output[..3].copy_from_slice(b"ABC");
        output.count = 3;
        output.consumed = 3;
        let preflight = output.receipt("incremental_raw", b"klm", b"ABC").unwrap();
        CALLS.store(0, std::sync::atomic::Ordering::SeqCst);
        assert!(
            run_batch(
                &mut output,
                &NativeOperation::Incremental(decode),
                "incremental_raw",
                b"klm",
                b"ABC",
                unused_crc,
                0,
                0,
                5,
                &preflight
            )
            .is_err()
        );
    }
    #[test]
    fn incorrect_intermediate_bytes_fail_even_with_correct_final_count_and_bytes() {
        static CALLS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn intermittent(
            _: c_int,
            _: *const c_void,
            destination: *mut c_void,
            _: usize,
            _: *mut c_int,
        ) -> usize {
            let call = CALLS.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            let bytes = if call == 2 { b"XBC" } else { b"ABC" };
            unsafe { std::ptr::copy_nonoverlapping(bytes.as_ptr(), destination.cast(), 3) };
            3
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        CALLS.store(0, std::sync::atomic::Ordering::SeqCst);
        let mut output = NativeOutput::new(3);
        output.output[..3].copy_from_slice(b"ABC");
        output.count = 3;
        let preflight = output.receipt("decode_ex", b"klm", b"ABC").unwrap();
        let function = NativeOperation::Decode(intermittent, false, false);
        assert!(
            run_batch(
                &mut output,
                &function,
                "decode_ex",
                b"klm",
                b"ABC",
                unused_crc,
                0,
                0,
                5,
                &preflight
            )
            .is_err()
        );
    }
    #[test]
    fn incorrect_middle_metadata_json_with_same_length_is_rejected() {
        static CALLS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn json_result(_: *const u8, _: usize) -> *mut c_char {
            let value = if CALLS.fetch_add(1, std::sync::atomic::Ordering::SeqCst) == 2 {
                "{\"v\":2}"
            } else {
                "{\"v\":1}"
            };
            std::ffi::CString::new(value).unwrap().into_raw()
        }
        unsafe extern "C" fn free(value: *mut c_char) {
            unsafe {
                drop(std::ffi::CString::from_raw(value));
            }
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        CALLS.store(0, std::sync::atomic::Ordering::SeqCst);
        let mut output = NativeOutput::new(3);
        output.json = b"{\"v\":1}".to_vec();
        output.number = output.json.len() as u64;
        let preflight = output
            .receipt("nntmux_format_metadata_json", b"hdr", b"hdr")
            .unwrap();
        let function = NativeOperation::Json(json_result, free);
        assert!(
            run_batch(
                &mut output,
                &function,
                "nntmux_format_metadata_json",
                b"hdr",
                b"hdr",
                unused_crc,
                0,
                0,
                5,
                &preflight
            )
            .is_err()
        );
    }
    #[test]
    fn direct_witness_rejects_every_extent_state_crc_and_raw_byte_mutation() {
        let mut verified = NativeOutput::new(3);
        verified.output[..3].copy_from_slice(b"ABC");
        verified.count = 3;
        verified.json = b"{\"v\":1}".to_vec();
        verified.number = verified.json.len() as u64;
        verified.html = b"first\0name\xffsecond".to_vec();
        verified
            .receipt("nntmux_format_metadata_json", b"hdr", b"hdr")
            .unwrap();
        let witness = VerifiedByteWitness::capture(&verified);
        assert!(witness.matches(&verified));
        for field in [
            "count", "crc", "state", "consumed", "end", "number", "decoded", "canary", "metadata",
            "html",
        ] {
            let mut changed = NativeOutput::new(3);
            changed.output[..3].copy_from_slice(b"ABC");
            changed.count = 3;
            changed.json = verified.json.clone();
            changed.number = verified.number;
            changed.html = verified.html.clone();
            match field {
                "count" => changed.count = usize::MAX,
                "crc" => changed.crc = 1,
                "state" => changed.state = 1,
                "consumed" => changed.consumed = 1,
                "end" => changed.end = 1,
                "number" => changed.number += 1,
                "decoded" => changed.output[1] = b'X',
                "canary" => changed.output[3] = 0,
                "metadata" => changed.json[5] = b'2',
                "html" => changed.html[5] = b'X',
                _ => unreachable!(),
            }
            assert!(!witness.matches(&changed), "unnoticed {field} mutation");
        }
    }
    #[test]
    fn semantically_identical_reordered_raw_json_is_rejected() {
        let mut verified = NativeOutput::new(0);
        verified.json = b"{\"a\":1,\"b\":2}".to_vec();
        verified.number = verified.json.len() as u64;
        verified
            .receipt("nntmux_format_metadata_json", b"hdr", b"hdr")
            .unwrap();
        let mut changed = NativeOutput::new(0);
        changed.json = b"{\"b\":2,\"a\":1}".to_vec();
        changed.number = changed.json.len() as u64;
        assert_eq!(
            serde_json::from_slice::<Value>(&verified.json).unwrap(),
            serde_json::from_slice::<Value>(&changed.json).unwrap()
        );
        assert!(!VerifiedByteWitness::capture(&verified).matches(&changed));
    }
    #[test]
    fn bounded_chunk_policy_cannot_hide_unbounded_byte_witness_storage() {
        assert_eq!(chunk_slots(4096, false, true).unwrap(), 8);
        assert_eq!(chunk_slots(8 * 1024 * 1024, false, true).unwrap(), 3);
        assert_eq!(chunk_slots(16 * 1024 * 1024, false, true).unwrap(), 1);
        assert_eq!(
            chunk_slots(usize::MAX, false, false).unwrap(),
            SCALAR_CHUNK_SLOTS
        );
        assert!(chunk_slots(32 * 1024 * 1024, false, true).is_err());
    }
    #[test]
    fn arbitrary_digest_for_tiny_receipt_corpus_is_not_a_full_replay_proof() {
        let root = temporary("fake-corpus");
        let path = root.join("tiny.json");
        write_json(&path, &json!({"decode":[]})).unwrap();
        let digest = file_sha256(&path).unwrap();
        assert!(
            validate_trusted_corpus(
                &FrozenJson::supplied(path.to_str().unwrap(), &digest).unwrap()
            )
            .is_err()
        );
        fs::remove_dir_all(root).unwrap();
    }
    #[test]
    fn fake_scalar_reference_dispatch_cannot_pass_runtime_guard() {
        assert!(validate_reference_dispatch(4096, 72).is_ok());
        for pair in [(0, 72), (4096, 0), (0, 0), (4096, 68)] {
            assert!(validate_reference_dispatch(pair.0, pair.1).is_err());
        }
    }
    #[test]
    fn scalar_witnesses_use_bounded_chunks_of_four_thousand_ninety_six() {
        unsafe extern "C" fn number() -> c_int {
            1
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        let mut output = NativeOutput::new(0);
        output.number = 1;
        let preflight = output
            .receipt("nntmux_yenc_supports_split_escapes", b"", b"")
            .unwrap();
        let batch = run_batch(
            &mut output,
            &NativeOperation::Number(number),
            "nntmux_yenc_supports_split_escapes",
            b"",
            b"",
            unused_crc,
            0,
            0,
            10000,
            &preflight,
        )
        .unwrap();
        assert_eq!(batch.slots, 4096);
        assert_eq!(batch.chunks, 3);
        assert_eq!(batch.verified, 10000);
        assert_eq!(chunk_slots(usize::MAX, false, false).unwrap(), 4096);
    }
    #[test]
    #[ignore = "explicit candidate artifact required: NNTMUX_BENCH_TEST_LIBRARY; resolves signatures without invoking individual lifecycle exports"]
    fn fingerprint_loader_rejects_individual_lifecycle_operations() {
        let artifact = std::env::var("NNTMUX_BENCH_TEST_LIBRARY").unwrap();
        let library = Library::open(Path::new(&artifact)).unwrap();
        assert!(matches!(
            unsafe { NativeOperation::load(&library, crate::tooling::FINGERPRINT_OPERATION) }
                .unwrap(),
            NativeOperation::Fingerprint(_)
        ));
        assert!(matches!(
            unsafe { NativeOperation::load(&library, "nntmux_format_metadata_json") }.unwrap(),
            NativeOperation::Json(..)
        ));
        assert!(matches!(
            unsafe { NativeOperation::load(&library, "decode_ex") }.unwrap(),
            NativeOperation::Decode(..)
        ));
        for (operation, _) in metadata_fixtures() {
            assert!(
                unsafe { NativeOperation::load(&library, &operation) }.is_ok(),
                "existing native workload rejected: {operation}"
            );
        }
        let mut accepted = Vec::new();
        for operation in [
            "nntmux_article_fingerprint_add",
            "nntmux_article_fingerprint_value",
            "nntmux_article_fingerprint_free",
        ] {
            // Prove rejection is from dispatch policy, not an absent artifact export.
            unsafe { library.symbol::<*mut c_void>(operation) }.unwrap();
            if unsafe { NativeOperation::load(&library, operation) }.is_ok() {
                accepted.push(operation);
            }
        }
        assert!(
            accepted.is_empty(),
            "loader admitted wrong-signature fingerprint operations: {accepted:?}"
        );
    }

    #[test]
    fn fingerprint_lifecycle_adds_exactly_one_incumbent_comparison() {
        let path = temporary("fingerprint-inventory");
        let works = workloads(&path.join("fixtures")).unwrap();
        let fingerprint = works
            .iter()
            .filter(|w| w.operation == "nntmux_article_fingerprint_new")
            .collect::<Vec<_>>();
        assert_eq!(
            fingerprint.len(),
            1,
            "missing bounded fingerprint lifecycle"
        );
        assert!(fingerprint[0].metadata && !fingerprint[0].php);
        assert_eq!(works.iter().filter(|w| !w.metadata).count(), 128);
        assert_eq!(works.iter().filter(|w| incumbent_supported(w)).count(), 69);
        fs::remove_dir_all(path).unwrap();
    }

    #[test]
    fn fingerprint_receipt_and_direct_witness_reject_each_status_byte_and_canary_mutation() {
        let input = crate::tooling::fingerprint_fixture();
        let mut output = NativeOutput::new(input.len());
        output.fingerprint = crate::tooling::expected_fingerprint_trace();
        output.number = output.fingerprint.len() as u64;
        assert_eq!(
            output
                .receipt(crate::tooling::FINGERPRINT_OPERATION, &input, &input)
                .unwrap(),
            expected_fingerprint_receipt()
        );
        let witness = VerifiedByteWitness::capture(&output);
        let mut changed = NativeOutput::new(input.len());
        changed.number = output.number;
        changed.fingerprint = output.fingerprint.clone();
        for i in 0..changed.fingerprint.len() {
            changed.fingerprint[i] ^= 1;
            assert!(
                changed
                    .receipt(crate::tooling::FINGERPRINT_OPERATION, &input, &input)
                    .is_err(),
                "accepted byte {i}"
            );
            assert!(!witness.matches(&changed), "missed intermediate byte {i}");
            changed.fingerprint[i] ^= 1;
        }
        changed.number += 1;
        assert!(!witness.matches(&changed));
        assert!(
            changed
                .receipt(crate::tooling::FINGERPRINT_OPERATION, &input, &input)
                .is_err()
        );
        assert!(operation_needs_bytes(crate::tooling::FINGERPRINT_OPERATION));
        assert!(allocated_metadata(crate::tooling::FINGERPRINT_OPERATION));
        assert_eq!(chunk_slots(input.len(), true, true).unwrap(), 8);
    }

    #[test]
    fn release_name_fixture_inventory_covers_both_new_native_exports() {
        let names = metadata_fixtures()
            .into_iter()
            .map(|(name, _)| name)
            .collect::<Vec<_>>();
        for expected in ["nntmux_release_names_html", "nntmux_release_names_free"] {
            assert!(names.iter().any(|name| name == expected), "{expected}");
        }
    }
    #[test]
    fn typed_html_call_preserves_embedded_nuls_and_passes_exact_length_to_free() {
        static FREED: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn renderer(_: *const u8, _: usize, length: *mut usize) -> *mut u8 {
            let mut output = b"first\0name\xffsecond\0name".to_vec().into_boxed_slice();
            unsafe { *length = output.len() };
            let pointer = output.as_mut_ptr();
            std::mem::forget(output);
            pointer
        }
        unsafe extern "C" fn free(pointer: *mut u8, length: usize) {
            assert_eq!(length, b"first\0name\xffsecond\0name".len());
            FREED.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            unsafe {
                drop(Box::from_raw(std::ptr::slice_from_raw_parts_mut(
                    pointer, length,
                )))
            };
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        FREED.store(0, std::sync::atomic::Ordering::SeqCst);
        let mut output = NativeOutput::new(0);
        unsafe {
            output.invoke(
                &NativeOperation::Html(renderer, free),
                b"{}",
                b"{}",
                unused_crc,
                0,
                0,
            )
        };
        assert_eq!(output.html, b"first\0name\xffsecond\0name");
        assert_eq!(output.number, output.html.len() as u64);
        assert_eq!(FREED.load(std::sync::atomic::Ordering::SeqCst), 1);
    }
    #[test]
    fn html_receipt_uses_bytes_length_and_delimiters_without_json_parsing() {
        let mut output = NativeOutput::new(0);
        output.html = b"first\0name\xffsecond\0name".to_vec();
        output.number = output.html.len() as u64;
        let receipt = output
            .receipt("nntmux_release_names_html", b"{}", b"{}")
            .unwrap();
        assert_eq!(receipt["html"]["sha256"], hash(&output.html));
        assert_eq!(receipt["html"]["length"], output.html.len());
        assert_eq!(receipt["html"]["delimiter_offsets"], json!([10]));
        assert_eq!(receipt["html"]["nul_offsets"], json!([5, 17]));
        assert!(receipt["metadata"].is_null());
    }
    #[test]
    fn same_length_middle_html_corruption_is_rejected_before_slot_reuse() {
        static CALLS: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
        unsafe extern "C" fn render(_: *const u8, _: usize, length: *mut usize) -> *mut u8 {
            let mut bytes = expected_release_names_html();
            if CALLS.fetch_add(1, std::sync::atomic::Ordering::SeqCst) == 2 {
                let index = bytes.iter().position(|byte| *byte == 0).unwrap();
                bytes[index] = b'X';
            }
            let mut bytes = bytes.into_boxed_slice();
            unsafe { *length = bytes.len() };
            let pointer = bytes.as_mut_ptr();
            std::mem::forget(bytes);
            pointer
        }
        unsafe extern "C" fn free(pointer: *mut u8, length: usize) {
            unsafe {
                drop(Box::from_raw(std::ptr::slice_from_raw_parts_mut(
                    pointer, length,
                )))
            }
        }
        unsafe extern "C" fn unused_crc(_: *const c_void, _: usize, _: u32) -> u32 {
            0
        }
        CALLS.store(0, std::sync::atomic::Ordering::SeqCst);
        let fixture = release_names_fixture();
        let mut output = NativeOutput::new(fixture.len());
        output.html = expected_release_names_html();
        output.number = output.html.len() as u64;
        let receipt = output
            .receipt("nntmux_release_names_html", &fixture, &fixture)
            .unwrap();
        assert_eq!(
            receipt["html"]["delimiter_offsets"]
                .as_array()
                .unwrap()
                .len(),
            1
        );
        assert_eq!(receipt["html"]["nul_offsets"].as_array().unwrap().len(), 4);
        assert!(
            run_batch(
                &mut output,
                &NativeOperation::Html(render, free),
                "nntmux_release_names_html",
                &fixture,
                &fixture,
                unused_crc,
                0,
                0,
                5,
                &receipt
            )
            .is_err()
        );
    }
}
