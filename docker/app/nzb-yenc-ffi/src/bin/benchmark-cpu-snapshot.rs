use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    env, fs,
    io::Write,
    path::{Path, PathBuf},
    process::{Command, Stdio},
    thread,
    time::{Duration, Instant},
};
const SEED: u64 = 20261014;
const PROTOCOL_VERSION: u64 = 3;
const BOOTSTRAP_RESAMPLES: usize = 4000;
const HOST_CONFIDENCE_FAMILY_SIZE: usize = 2;
const ADAPTER: &str = "import importlib.util,sys; from pathlib import Path; s=importlib.util.spec_from_file_location('cpu_baseline',sys.argv[1]); m=importlib.util.module_from_spec(s); s.loader.exec_module(m); sys.exit(m.main(['--output',sys.argv[2]],Path(sys.argv[3])))";
const STAT: &str = "cpu  102 0 31 809 5 0 3 0 0 0\ncpu0 102 0 31 809 5 0 3 0 0 0\n";
const PRESSURE: &str = "some avg10=0.00 avg60=0.01 avg300=0.02 total=54321\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n";
const CPU_FILES: [(&str, &str); 4] = [
    (
        "cpu.stat",
        "usage_usec 10500\nuser_usec 10000\nsystem_usec 500\nnr_periods 12\nnr_throttled 2\nthrottled_usec 35\n",
    ),
    ("cpu.stat.local", "throttled_usec 35\n"),
    ("cpu.max", "200000 100000\n"),
    ("cpu.pressure", PRESSURE),
];
struct Options {
    baseline: PathBuf,
    candidate: PathBuf,
    project: PathBuf,
    python: String,
    pairs: usize,
    host_pairs: usize,
    output: PathBuf,
}
fn parse(args: Vec<String>) -> Result<Options, String> {
    let mut o = Options {
        baseline: PathBuf::new(),
        candidate: env::current_exe()
            .map_err(|e| e.to_string())?
            .with_file_name("nntmux-cpu-snapshot"),
        project: env::current_dir().map_err(|e| e.to_string())?,
        python: "python3".into(),
        pairs: 1000,
        host_pairs: 0,
        output: PathBuf::new(),
    };
    for a in args {
        let (k, v) = a
            .strip_prefix("--")
            .and_then(|a| a.split_once('='))
            .ok_or_else(|| format!("Invalid argument {a}"))?;
        match k {
            "baseline" => o.baseline = v.into(),
            "candidate" => o.candidate = v.into(),
            "project" => o.project = v.into(),
            "python" if !v.is_empty() => o.python = v.into(),
            "output" => o.output = v.into(),
            "pairs" => o.pairs = v.parse().map_err(|_| "Invalid pairs")?,
            "host-pairs" => o.host_pairs = parse_host_pairs(v)?,
            _ => return Err(format!("Unknown option {k}")),
        }
    }
    if !o.baseline.is_absolute() || !o.baseline.is_file() {
        return Err("--baseline requires an absolute original Python source file".into());
    }
    if !(1..=5000).contains(&o.pairs) || o.output.as_os_str().is_empty() {
        return Err("--pairs must be 1..5000; --output must name a new evidence directory".into());
    }
    o.baseline = fs::canonicalize(o.baseline).map_err(|e| e.to_string())?;
    o.project = fs::canonicalize(o.project).map_err(|e| e.to_string())?;
    o.candidate = fs::canonicalize(o.candidate).map_err(|e| e.to_string())?;
    if !o.output.is_absolute() {
        o.output = env::current_dir()
            .map_err(|e| e.to_string())?
            .join(o.output)
    }
    Ok(o)
}
fn parse_host_pairs(value: &str) -> Result<usize, String> {
    let pairs = value.parse::<usize>().map_err(|_| "Invalid host-pairs")?;
    if !(1..=5000).contains(&pairs) {
        return Err(
            "--host-pairs must be 1..5000; p99 qualification requires at least 1000".into(),
        );
    }
    Ok(pairs)
}
fn hash(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}
fn file_hash(path: &Path) -> Result<String, String> {
    fs::read(path)
        .map(|b| hash(&b))
        .map_err(|e| format!("{}: {e}", path.display()))
}
// SplitMix64 by Sebastiano Vigna, public domain:
// https://prng.di.unimi.it/splitmix64.c . Zero is a valid seed.
fn next(state: &mut u64) -> u64 {
    *state = state.wrapping_add(0x9e37_79b9_7f4a_7c15);
    let mut z = *state;
    z = (z ^ (z >> 30)).wrapping_mul(0xbf58_476d_1ce4_e5b9);
    z = (z ^ (z >> 27)).wrapping_mul(0x94d0_49bb_1331_11eb);
    z ^ (z >> 31)
}
fn bounded_index(state: &mut u64, upper: usize) -> usize {
    assert!(upper > 0);
    let bound = upper as u64;
    // Discard the short prefix so the accepted u64 range has a multiple
    // of `bound` elements. Every result then has the same number of preimages.
    let threshold = bound.wrapping_neg() % bound;
    loop {
        let value = next(state);
        if value >= threshold {
            return (value % bound) as usize;
        }
    }
}
fn orders(n: usize) -> Vec<bool> {
    let mut state = SEED;
    (0..n).map(|_| bounded_index(&mut state, 2) == 1).collect()
}
fn synthetic_confidence_family_size(fixture_count: usize, host_proc_absent: bool) -> usize {
    fixture_count + usize::from(host_proc_absent)
}
fn confidence_metadata(family_size: usize) -> Value {
    json!({"version":PROTOCOL_VERSION,"order_rng":"splitmix64-rejection","bootstrap_rng":"splitmix64-rejection","bootstrap_resamples":BOOTSTRAP_RESAMPLES,"bootstrap_seed":SEED,"confidence_method":"paired_bootstrap_bonferroni","confidence_family_size":family_size,"confidence_level":0.95})
}
fn host_confidence_metadata() -> Value {
    let mut protocol = confidence_metadata(HOST_CONFIDENCE_FAMILY_SIZE);
    protocol.as_object_mut().unwrap().extend(json!({"confidence_family":"Exact Linux host CLI median and p99 gates; separate from synthetic/absent-proc family; other intervals diagnostic per-statistic95%","minimum_p99_pairs":1000,"median_ratio_ci_lower_gate":1.5,"p99_ratio_ci_lower_gate":1.5}).as_object().unwrap().clone());
    protocol
}
fn percentile(values: &[f64], p: f64) -> Option<f64> {
    if values.is_empty()
        || !(0.0..=1.0).contains(&p)
        || values.iter().any(|v| !v.is_finite() || *v <= 0.)
    {
        return None;
    }
    let mut v = values.to_vec();
    v.sort_by(f64::total_cmp);
    Some(
        v[((p * v.len() as f64).ceil() as usize)
            .saturating_sub(1)
            .min(v.len() - 1)],
    )
}
fn median(values: &[f64]) -> Option<f64> {
    let lo = percentile(values, 0.5)?;
    if values.len() % 2 == 1 {
        Some(lo)
    } else {
        let mut v = values.to_vec();
        v.sort_by(f64::total_cmp);
        Some((lo + v[v.len() / 2]) / 2.)
    }
}
struct MetricIntervals {
    summary: Value,
    median: Value,
    p99: Value,
}
fn metric_intervals(b: &[f64], c: &[f64], family_size: usize) -> Option<MetricIntervals> {
    if b.len() != c.len() || family_size == 0 {
        return None;
    }
    let bm = median(b)?;
    let cm = median(c)?;
    let bp = percentile(b, 0.99)?;
    let cp = percentile(c, 0.99)?;
    let paired: Vec<f64> = b.iter().zip(c).map(|(b, c)| b / c).collect();
    let mut state = SEED;
    let mut med = Vec::new();
    let mut tail = Vec::new();
    for _ in 0..BOOTSTRAP_RESAMPLES {
        let mut rb = Vec::with_capacity(b.len());
        let mut rc = Vec::with_capacity(c.len());
        for _ in b {
            let i = bounded_index(&mut state, b.len());
            rb.push(b[i]);
            rc.push(c[i]);
        }
        med.push(median(&rb)? / median(&rc)?);
        tail.push(percentile(&rb, 0.99)? / percentile(&rc, 0.99)?);
    }
    let alpha = 0.05 / family_size as f64 / 2.;
    Some(MetricIntervals {
        summary: json!({"valid_pairs":b.len(),"baseline_median_ns":bm,"candidate_median_ns":cm,"median_ratio":bm/cm,"paired_median_ratio":median(&paired),"speedup_percent":100.*(bm/cm-1.),"baseline_p99_ns":bp,"candidate_p99_ns":cp,"p99_ratio":bp/cp,"median_ratio_ci95":[percentile(&med,0.025),percentile(&med,0.975)],"p99_ratio_ci95":[percentile(&tail,0.025),percentile(&tail,0.975)],"expected_samples_above_p99":b.len() as f64*0.01,"p99_sample_sufficient":b.len()>=1000}),
        median: json!([percentile(&med, alpha), percentile(&med, 1. - alpha)]),
        p99: json!([percentile(&tail, alpha), percentile(&tail, 1. - alpha)]),
    })
}
fn metrics(b: &[f64], c: &[f64], family_size: usize, rare: bool, host: bool) -> Option<Value> {
    let intervals = metric_intervals(b, c, family_size)?;
    let mut result = intervals.summary;
    result["gate_statistic"] = json!(if rare { "p99_ratio" } else { "median_ratio" });
    result["gate_speedup_simultaneous_ci95"] = if rare {
        intervals.p99
    } else {
        intervals.median.clone()
    };
    if host {
        result["host_median_speedup_simultaneous_ci95"] = intervals.median;
    }
    Some(result)
}
fn host_metrics(b: &[f64], c: &[f64]) -> Option<Value> {
    let intervals = metric_intervals(b, c, HOST_CONFIDENCE_FAMILY_SIZE)?;
    let mut result = intervals.summary;
    result["live_median_speedup_simultaneous_ci95"] = intervals.median;
    result["live_p99_speedup_simultaneous_ci95"] = intervals.p99;
    Some(result)
}
fn host_confidence_gate_passes(metrics: &Value) -> bool {
    [
        "live_median_speedup_simultaneous_ci95",
        "live_p99_speedup_simultaneous_ci95",
    ]
    .iter()
    .all(|field| {
        metrics[*field][0]
            .as_f64()
            .is_some_and(|ratio| ratio >= 1.5)
    })
}
fn confidence_gate_passes(metrics: &Value, rare: bool, host: bool) -> bool {
    metrics["gate_speedup_simultaneous_ci95"][0]
        .as_f64()
        .is_some_and(|r| r >= if rare { 1.5 } else { 2. })
        && (!host
            || metrics["host_median_speedup_simultaneous_ci95"][0]
                .as_f64()
                .is_some_and(|r| r >= 2.))
}
fn captured(path: &Path, raw: &str) -> Value {
    json!({"path":path,"status":"captured","raw":raw})
}
fn messages_valid(row: &Value, exit: i32) -> bool {
    row["exit_code"] == exit
        && row["timed_out"] == false
        && row["process_error"].is_null()
        && row["stdout"] == ""
        && row["stderr"]
            == if exit == 1 {
                "CPU evidence retained but no usable OS CPU counters\n"
            } else {
                ""
            }
}
fn normalize(v: &mut Value) {
    match v {
        Value::Object(o) => {
            if o.contains_key("schema_version") {
                o.remove("captured_at");
                o.remove("monotonic_ns");
            }
            let read_error = o.get("path").is_some_and(Value::is_string)
                && o.get("status").is_some_and(|v| v == "unavailable");
            for (k, v) in o {
                if k == "error" && read_error && v.is_string() {
                    *v = json!("ERROR_PRESENT");
                } else {
                    normalize(v)
                }
            }
        }
        Value::Array(a) => {
            for v in a {
                normalize(v)
            }
        }
        _ => (),
    }
}
struct Fixture {
    name: &'static str,
    rare: bool,
    proc: PathBuf,
    host: bool,
    golden: Value,
    exit: i32,
    inputs: Value,
}
fn fixture(base: &Path, name: &'static str, depth: usize, bad: bool) -> Result<Fixture, String> {
    let proc = base.join(name).join("proc");
    let mount = base.join(name).join("cgroup mounted");
    fs::create_dir_all(proc.join("self")).map_err(|e| e.to_string())?;
    fs::create_dir_all(proc.join("pressure")).map_err(|e| e.to_string())?;
    let member = if depth == 2 {
        "/team/job".into()
    } else {
        format!(
            "/{}",
            (0..depth)
                .map(|n| format!("d{n:02}"))
                .collect::<Vec<_>>()
                .join("/")
        )
    };
    let membership = if bad {
        "2:cpu:/legacy\n".into()
    } else {
        format!("0::{member}\n")
    };
    let mountinfo = format!(
        "29 23 0:26 / {} rw,nosuid,nodev,noexec,relatime - cgroup2 cgroup rw\n",
        mount.to_string_lossy().replace(' ', "\\040")
    );
    let stat = if bad { "cpu 1 -2 3 4\n" } else { STAT };
    let load = "0.10 0.20 0.30 1/99 1234\n";
    let mut inputs = json!({});
    for (p, raw) in [
        (proc.join("stat"), stat),
        (proc.join("pressure/cpu"), PRESSURE),
        (proc.join("loadavg"), load),
        (proc.join("self/cgroup"), membership.as_str()),
        (proc.join("self/mountinfo"), mountinfo.as_str()),
    ] {
        fs::write(&p, raw).map_err(|e| e.to_string())?;
        inputs[p.to_string_lossy().as_ref()] = json!(hash(raw.as_bytes()));
    }
    let mut cg = json!({"status":"unavailable","membership":captured(&proc.join("self/cgroup"),&membership),"mountinfo":captured(&proc.join("self/mountinfo"),&mountinfo),"above_mount_root":"unavailable","scope":"Mounted view only; hidden host/namespace ancestors unavailable"});
    if bad {
        cg["error"] = json!("No unambiguous supported cgroup v2 membership (v1 unsupported)");
    } else {
        let mut current = mount.join(member.trim_start_matches('/'));
        let mut ancestors = vec![];
        loop {
            fs::create_dir_all(&current).map_err(|e| e.to_string())?;
            let mut a = json!({"path":current});
            for (name, raw) in CPU_FILES {
                let p = current.join(name);
                fs::write(&p, raw).map_err(|e| e.to_string())?;
                inputs[p.to_string_lossy().as_ref()] = json!(hash(raw.as_bytes()));
                a[name] = captured(&p, raw);
            }
            ancestors.push(a);
            if current == mount {
                break;
            }
            current = current.parent().unwrap().to_owned();
        }
        cg["status"] = json!("captured");
        cg["membership_path"] = json!(member);
        cg["mount_root"] = json!("/");
        cg["mountpoint"] = json!(mount);
        cg["ancestors"] = json!(ancestors);
    }
    let mut golden = json!({"schema_version":1,"os_cpu_usable":!bad,"os":{"stat":captured(&proc.join("stat"),stat),"pressure_cpu":captured(&proc.join("pressure/cpu"),PRESSURE),"loadavg":captured(&proc.join("loadavg"),load)},"cgroup_v2":cg});
    if bad {
        golden["os"]["stat"]["validation_error"] = json!("No usable aggregate CPU tick counters");
    }
    Ok(Fixture {
        name,
        rare: depth > 2 || bad,
        proc,
        host: false,
        golden,
        exit: if bad { 1 } else { 0 },
        inputs,
    })
}
fn absent_fixture(proc: &Path) -> Fixture {
    let unavailable = |relative: &str| json!({"path":proc.join(relative),"status":"unavailable","error":"ERROR_PRESENT"});
    let mut stat = unavailable("stat");
    stat["validation_error"] = json!("No usable aggregate CPU tick counters");
    let golden = json!({"schema_version":1,"os_cpu_usable":false,"os":{"stat":stat,"pressure_cpu":unavailable("pressure/cpu"),"loadavg":unavailable("loadavg")},"cgroup_v2":{"status":"unavailable","membership":unavailable("self/cgroup"),"mountinfo":unavailable("self/mountinfo"),"above_mount_root":"unavailable","scope":"Mounted view only; hidden host/namespace ancestors unavailable","error":"No unambiguous supported cgroup v2 membership (v1 unsupported)"}});
    Fixture {
        name: "rare-host-unavailable",
        rare: true,
        proc: proc.to_owned(),
        host: true,
        golden,
        exit: 1,
        inputs: json!({}),
    }
}
fn valid(data: &Value, golden: Option<&Value>) -> bool {
    let timestamp = data["captured_at"].as_str().unwrap_or("");
    if data["schema_version"] != 1
        || data["monotonic_ns"].as_u64().unwrap_or(0) == 0
        || !timestamp.ends_with("+00:00")
        || !timestamp.contains('T')
    {
        return false;
    }
    if let Some(g) = golden {
        let mut n = data.clone();
        normalize(&mut n);
        n == *g
    } else {
        data["os_cpu_usable"].is_boolean()
            && data["os"].is_object()
            && data["cgroup_v2"].is_object()
    }
}
// Each completed record reaches the OS before admitting the next child.
// A partial last write poisons the journal; earlier newline-delimited records
// remain readable, and an interrupted run never claims a final report.
struct ObservationJournal<W: Write = fs::File> {
    writer: W,
    sequence: u64,
    failed: bool,
}
impl ObservationJournal<fs::File> {
    fn create(path: &Path) -> Result<Self, String> {
        let writer = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(path)
            .map_err(|error| format!("Create observation journal {}: {error}", path.display()))?;
        Ok(Self {
            writer,
            sequence: 0,
            failed: false,
        })
    }
}
impl<W: Write> ObservationJournal<W> {
    fn append(&mut self, event: &str, details: &Value) -> Result<(), String> {
        if self.failed {
            return Err("Observation journal is incomplete after a write/flush failure".into());
        }
        let record = json!({"journal_schema_version":1,"sequence":self.sequence,"event":event,"details":details});
        let mut encoded = serde_json::to_vec(&record).map_err(|error| error.to_string())?;
        encoded.push(b'\n');
        if let Err(error) = self
            .writer
            .write_all(&encoded)
            .and_then(|_| self.writer.flush())
        {
            self.failed = true;
            return Err(format!(
                "Observation journal record {} {event}: {error}",
                self.sequence
            ));
        }
        self.sequence += 1;
        Ok(())
    }
}
fn journal_identity(output: &Path) -> Value {
    json!({"path":output.join("observations.jsonl"),"format":"JSONL","schema_version":1,"timing":"Append and flush after validation of each completed child, outside process wall timing and before the next child","durability":"write_all newline then flush; no fsync/power-loss guarantee. Complete earlier records survive process cancellation; a partial final record is retained and invalid","event_order":"Strict increasing sequence across preflight, source identities, setup, protocols, child observations, summaries and terminal status"})
}
fn execute(
    o: &Options,
    which: &str,
    proc: Option<&Path>,
    dir: &Path,
    label: &str,
) -> Result<Value, String> {
    let output = dir.join(format!("{label}.json"));
    let stdout = dir.join(format!("{label}.stdout"));
    let stderr = dir.join(format!("{label}.stderr"));
    let mut command = if which == "baseline" {
        let mut c = Command::new(&o.python);
        if let Some(proc) = proc {
            c.args(["-c", ADAPTER])
                .arg(&o.baseline)
                .arg(&output)
                .arg(proc);
        } else {
            c.arg(&o.baseline).arg("--output").arg(&output);
        }
        c.env("PYTHONDONTWRITEBYTECODE", "1");
        c
    } else {
        let mut c = Command::new(&o.candidate);
        c.arg("--output").arg(&output);
        if let Some(proc) = proc {
            c.arg("--proc-root").arg(proc);
        }
        c
    };
    command.current_dir(&o.project);
    let argv: Vec<_> = std::iter::once(command.get_program())
        .chain(command.get_args())
        .map(|a| a.to_string_lossy().into_owned())
        .collect();
    let streams = (|| -> Result<(fs::File, fs::File), String> {
        Ok((
            fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(&stdout)
                .map_err(|e| e.to_string())?,
            fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(&stderr)
                .map_err(|e| e.to_string())?,
        ))
    })();
    let (stdout_file, stderr_file) = match streams {
        Ok(files) => files,
        Err(error) => {
            return Ok(
                json!({"implementation":which,"argv":argv,"elapsed_ns":0,"exit_code":null,"process_error":format!("Cannot exclusively create child streams: {error}"),"timed_out":false,"output_path":output,"output_sha256":null,"data":null,"stdout_path":stdout,"stderr_path":stderr,"stdout":null,"stderr":null}),
            );
        }
    };
    command
        .stdout(Stdio::from(stdout_file))
        .stderr(Stdio::from(stderr_file));
    let start = Instant::now();
    let spawned = command.spawn();
    let mut timeout = false;
    let status = match spawned {
        Ok(mut child) => loop {
            match child.try_wait() {
                Ok(Some(s)) => break Ok(s),
                Ok(None) if start.elapsed() < Duration::from_secs(5) => {
                    thread::sleep(Duration::from_micros(250))
                }
                Ok(None) => {
                    timeout = true;
                    let _ = child.kill();
                    break child.wait();
                }
                Err(e) => {
                    let _ = child.kill();
                    let _ = child.wait();
                    break Err(e);
                }
            }
        },
        Err(e) => Err(e),
    };
    let elapsed = start.elapsed().as_nanos();
    let parsed = fs::read(&output)
        .map_err(|e| e.to_string())
        .and_then(|bytes| serde_json::from_slice::<Value>(&bytes).map_err(|e| e.to_string()));
    let data = parsed.as_ref().ok();
    let output_error = parsed.as_ref().err();
    Ok(
        json!({"implementation":which,"argv":argv,"elapsed_ns":elapsed,"exit_code":status.as_ref().ok().and_then(|s|s.code()),"process_error":status.err().map(|e|e.to_string()),"timed_out":timeout,"output_path":output,"output_sha256":file_hash(&output).ok(),"data":data,"output_error":output_error,"stdout_path":stdout,"stderr_path":stderr,"stdout":fs::read_to_string(&stdout).ok(),"stderr":fs::read_to_string(&stderr).ok()}),
    )
}
#[derive(Clone, Copy, PartialEq)]
enum FieldKind {
    Counter,
    Gauge,
    Static,
}
struct RawField {
    key: String,
    value: Value,
    kind: FieldKind,
}
fn uint(value: &str) -> Result<u64, String> {
    if value.is_empty() || !value.bytes().all(|b| b.is_ascii_digit()) {
        return Err(format!("Non-integer counter: {value}"));
    }
    value
        .parse()
        .map_err(|_| format!("Counter out of range: {value}"))
}
fn gauge(value: &str) -> Result<f64, String> {
    let value = value.parse::<f64>().map_err(|_| "Invalid gauge")?;
    if !value.is_finite() || value < 0. {
        return Err("Gauge must be finite and nonnegative".into());
    }
    Ok(value)
}
fn raw_fields(kind: &str, raw: &str) -> Result<Vec<RawField>, String> {
    let mut result = Vec::new();
    let mut add = |key: String, value: Value, kind| {
        result.push(RawField { key, value, kind });
    };
    match kind {
        "stat" | "cpu.stat" | "cpu.stat.local" => {
            let mut seen = std::collections::BTreeSet::new();
            for line in raw.lines() {
                let fields: Vec<_> = line.split_whitespace().collect();
                if fields.len() < 2 || !seen.insert(fields[0]) {
                    return Err("Missing or duplicate counter fields".into());
                }
                let name = fields[0];
                let cpu =
                    name == "cpu" || name.strip_prefix("cpu").is_some_and(|v| uint(v).is_ok());
                if kind == "stat"
                    && ((cpu && fields.len() < 5)
                        || (!cpu
                            && !matches!(
                                name,
                                "intr"
                                    | "ctxt"
                                    | "btime"
                                    | "processes"
                                    | "procs_running"
                                    | "procs_blocked"
                                    | "softirq"
                            )))
                {
                    return Err(format!("Unsupported /proc/stat shape: {name}"));
                }
                if kind != "stat" && fields.len() != 2 {
                    return Err("Invalid cgroup counter shape".into());
                }
                for (i, value) in fields[1..].iter().enumerate() {
                    let field_kind = if kind == "stat" && name == "btime" {
                        FieldKind::Static
                    } else if kind == "stat"
                        && (matches!(name, "procs_running" | "procs_blocked") || (cpu && i == 4))
                    {
                        // iowait can decrease on Linux; these fields have no monotonic contract.
                        FieldKind::Gauge
                    } else {
                        FieldKind::Counter
                    };
                    add(format!("{name}.{i}"), json!(uint(value)?), field_kind);
                }
            }
            if result.is_empty() || (kind == "stat" && !seen.contains("cpu")) {
                return Err("No aggregate CPU counters".into());
            }
        }
        "pressure_cpu" | "cpu.pressure" => {
            let mut seen = std::collections::BTreeSet::new();
            for line in raw.lines() {
                let fields: Vec<_> = line.split_whitespace().collect();
                if fields.len() != 5
                    || !matches!(fields[0], "some" | "full")
                    || !seen.insert(fields[0])
                {
                    return Err("Invalid pressure line shape".into());
                }
                for (field, expected) in fields[1..]
                    .iter()
                    .zip(["avg10", "avg60", "avg300", "total"])
                {
                    let (name, value) = field.split_once('=').ok_or("Invalid pressure field")?;
                    if name != expected {
                        return Err("Invalid pressure field order".into());
                    }
                    let key = format!("{}.{}", fields[0], name);
                    if name == "total" {
                        add(key, json!(uint(value)?), FieldKind::Counter);
                    } else {
                        add(key, json!(gauge(value)?), FieldKind::Gauge);
                    }
                }
            }
            if !seen.contains("some") {
                return Err("No pressure some record".into());
            }
        }
        "loadavg" => {
            let fields: Vec<_> = raw.split_whitespace().collect();
            if fields.len() != 5 {
                return Err("Invalid loadavg shape".into());
            }
            for (i, value) in fields[..3].iter().enumerate() {
                add(
                    format!("average.{i}"),
                    json!(gauge(value)?),
                    FieldKind::Gauge,
                );
            }
            let (running, tasks) = fields[3].split_once('/').ok_or("Invalid loadavg tasks")?;
            for (key, value) in [
                ("running", running),
                ("tasks", tasks),
                ("last_pid", fields[4]),
            ] {
                add(key.into(), json!(uint(value)?), FieldKind::Gauge);
            }
        }
        _ => add("raw".into(), json!(raw), FieldKind::Static),
    }
    Ok(result)
}
fn raw_interval(kind: &str, before: &str, during: &str, after: &str) -> Result<Value, String> {
    let b = raw_fields(kind, before)?;
    let d = raw_fields(kind, during)?;
    let a = raw_fields(kind, after)?;
    if b.len() != d.len() || a.len() != d.len() {
        return Err("Counter shape changed during the interval".into());
    }
    let mut counters = 0;
    let mut gauges = 0;
    for ((b, d), a) in b.iter().zip(&d).zip(&a) {
        if b.key != d.key || a.key != d.key || b.kind != d.kind || a.kind != d.kind {
            return Err("Counter names/order changed during the interval".into());
        }
        match d.kind {
            FieldKind::Static if b.value != d.value || a.value != d.value => {
                return Err(format!("Static value changed: {}", d.key));
            }
            FieldKind::Counter => {
                let (before, during, after) = (
                    b.value.as_u64().unwrap(),
                    d.value.as_u64().unwrap(),
                    a.value.as_u64().unwrap(),
                );
                if before > during || during > after {
                    return Err(format!(
                        "Counter lacks a bounded monotonic witness: {} ({before}, {during}, {after})",
                        d.key
                    ));
                }
                counters += 1;
            }
            FieldKind::Gauge => gauges += 1,
            _ => (),
        }
    }
    Ok(
        json!({"bounded_counters_checked":counters,"finite_gauge_fields_checked":gauges,"raw_differs_from_before":during!=before,"raw_differs_from_after":during!=after}),
    )
}
fn raw_kind(path: &str) -> Option<&str> {
    match path {
        "/proc/stat" => Some("stat"),
        "/proc/loadavg" => Some("loadavg"),
        "/proc/pressure/cpu" => Some("pressure_cpu"),
        _ => Path::new(path)
            .file_name()
            .and_then(|name| name.to_str())
            .filter(|name| matches!(*name, "cpu.stat" | "cpu.stat.local" | "cpu.pressure")),
    }
}
fn live_shape(value: &Value) -> Result<Value, String> {
    let mut shape = value.clone();
    normalize(&mut shape);
    fn visit(value: &mut Value) -> Result<(), String> {
        match value {
            Value::Object(object) => {
                if let (Some(path), Some(raw)) = (
                    object.get("path").and_then(Value::as_str),
                    object.get("raw").and_then(Value::as_str),
                ) && let Some(kind) = raw_kind(path)
                {
                    let fields = raw_fields(kind, raw)?;
                    object.insert("raw".into(), json!(fields.iter().map(|field|json!({"key":field.key,"value":if field.kind==FieldKind::Static {field.value.clone()} else {json!("VARIABLE")}})).collect::<Vec<_>>()));
                }
                for value in object.values_mut() {
                    visit(value)?;
                }
            }
            Value::Array(array) => {
                for value in array {
                    visit(value)?;
                }
            }
            _ => (),
        }
        Ok(())
    }
    visit(&mut shape)?;
    Ok(shape)
}
fn raw_records(value: &Value) -> std::collections::BTreeMap<String, String> {
    fn visit(value: &Value, records: &mut std::collections::BTreeMap<String, String>) {
        match value {
            Value::Object(object) => {
                if let (Some(path), Some(raw)) = (
                    object.get("path").and_then(Value::as_str),
                    object.get("raw").and_then(Value::as_str),
                ) {
                    records.insert(path.into(), raw.into());
                }
                for value in object.values() {
                    visit(value, records);
                }
            }
            Value::Array(array) => {
                for value in array {
                    visit(value, records);
                }
            }
            _ => (),
        }
    }
    let mut result = std::collections::BTreeMap::new();
    visit(value, &mut result);
    result
}
fn host_validation(before: &Value, data: &Value, after: &Value) -> Value {
    let mut failures = Vec::new();
    let mut witnesses = json!({});
    for (name, value) in [("before", before), ("output", data), ("after", after)] {
        if !valid(value, None) || value["os_cpu_usable"] != true {
            failures.push(format!("{name}: no valid usable host CPU evidence"));
        }
    }
    match (live_shape(before), live_shape(data), live_shape(after)) {
        (Ok(b), Ok(d), Ok(a)) if b == d && a == d => (),
        (Err(error), _, _) | (_, Err(error), _) | (_, _, Err(error)) => failures.push(error),
        _ => failures
            .push("Static host source/cgroup mapping, statuses, or output shape differs".into()),
    }
    let b = before["monotonic_ns"].as_u64().unwrap_or(0);
    let d = data["monotonic_ns"].as_u64().unwrap_or(0);
    let a = after["monotonic_ns"].as_u64().unwrap_or(0);
    if b == 0 || b > d || d > a {
        failures.push("Output capture timestamp is outside the measured bracket".into());
    }
    let before_records = raw_records(before);
    let after_records = raw_records(after);
    for (path, raw) in raw_records(data) {
        match (before_records.get(&path), after_records.get(&path)) {
            (Some(b), Some(a)) => {
                match raw_interval(raw_kind(&path).unwrap_or("static"), b, &raw, a) {
                    Ok(witness) => witnesses[&path] = witness,
                    Err(error) => failures.push(format!("{path}: {error}")),
                }
            }
            _ => failures.push(format!("{path}: missing interval endpoints")),
        }
    }
    json!({"valid":failures.is_empty(),"failures":failures,"counter_witnesses":witnesses,"contract":"Static source/cgroup mapping and record shape agree; monotonic counters bounded by independent file reads before/after each CLI. Gauges and Linux iowait checked for finite nonnegative numeric shape only; all raw differences retained. This is interval consistency, not identical live counter values."})
}
fn host_endpoint() -> Value {
    let mut resources = json!({});
    for path in [
        "/proc/meminfo",
        "/proc/uptime",
        "/proc/self/limits",
        "/sys/devices/system/cpu/online",
    ] {
        resources[path] = match fs::read_to_string(path) {
            Ok(raw) => captured(Path::new(path), &raw),
            Err(error) => json!({"path":path,"status":"unavailable","error":error.to_string()}),
        };
    }
    json!({"cpu":nzbyenc::cpu_snapshot::snapshot(Path::new("/proc")),"resources":resources})
}
fn host_run(o: &Options, journal: &mut ObservationJournal) -> Result<Value, String> {
    let dir = o.output.join("host-paired");
    fs::create_dir(&dir).map_err(|e| e.to_string())?;
    let schedule = orders(o.host_pairs + 1);
    let before = host_endpoint();
    let mut protocol = host_confidence_metadata();
    protocol.as_object_mut().unwrap().extend(json!({"pairs":o.host_pairs,"seed":SEED,"orders_candidate_first":schedule,"order_sha256":hash(&serde_json::to_vec(&schedule).unwrap()),"warmup_pairs":1,"exact_default_cli":true,"baseline_bytecode_writes":false,"scope":"Read-only exact Linux host CLI cold-process warm-filesystem screen, including startup/reads/serialization/output. Never production throughput or adoption evidence."}).as_object().unwrap().clone());
    let mut journal_protocol = protocol.clone();
    journal_protocol["window_before"] = before.clone();
    journal.append("host_protocol", &journal_protocol)?;
    fs::write(
        dir.join("window-before.json"),
        serde_json::to_vec_pretty(&before).unwrap(),
    )
    .map_err(|e| e.to_string())?;
    let mut observations = Vec::new();
    let mut b = Vec::new();
    let mut c = Vec::new();
    let mut all_valid = true;
    for (pair, candidate_first) in schedule.iter().enumerate() {
        let order = if *candidate_first {
            ["candidate", "baseline"]
        } else {
            ["baseline", "candidate"]
        };
        let mut rows = json!({});
        for (order_index, which) in order.into_iter().enumerate() {
            let before = host_endpoint();
            let mut row = execute(o, which, None, &dir, &format!("{pair:04}-{which}"))?;
            let after = host_endpoint();
            let validation = host_validation(&before["cpu"], &row["data"], &after["cpu"]);
            let ok = validation["valid"] == true && messages_valid(&row, 0);
            row["interval_before"] = before;
            row["interval_after"] = after;
            row["validation"] = validation;
            row["valid"] = json!(ok);
            row["fixture"] = json!("exact-linux-host-cli");
            row["pair"] = json!(pair);
            row["warmup"] = json!(pair == 0);
            all_valid &= ok;
            rows[which] = row.clone();
            journal.append(
                "observation",
                &json!({"stratum":"exact-linux-host-cli","order_index":order_index,"row":row}),
            )?;
            observations.push(row);
        }
        if pair > 0 && rows["baseline"]["valid"] == true && rows["candidate"]["valid"] == true {
            b.push(rows["baseline"]["elapsed_ns"].as_f64().unwrap());
            c.push(rows["candidate"]["elapsed_ns"].as_f64().unwrap());
        }
    }
    let after = host_endpoint();
    fs::write(
        dir.join("window-after.json"),
        serde_json::to_vec_pretty(&after).unwrap(),
    )
    .map_err(|e| e.to_string())?;
    journal.append("host_window_end", &json!({"window_after":after}))?;
    let mut summary = host_metrics(&b, &c).unwrap_or(json!({"valid_pairs":0}));
    let sufficient = b.len() >= 1000;
    let pass =
        all_valid && b.len() == o.host_pairs && sufficient && host_confidence_gate_passes(&summary);
    summary["gate_pass"] = json!(pass);
    summary["required_pairs"] = json!(o.host_pairs);
    summary["invalid_observations"] =
        json!(observations.iter().filter(|r| r["valid"] != true).count());
    summary["outcome"] = json!(if !all_valid {
        "invalid"
    } else if !sufficient {
        "inconclusive-insufficient-p99"
    } else if pass {
        "qualified-host-cli-screen"
    } else {
        "negative-or-inconclusive-effect"
    });
    Ok(
        json!({"protocol":protocol,"summary":summary,"correctness_pass":all_valid,"host_gate_pass":pass,"observations":observations,"window_before":before,"window_after":after}),
    )
}

fn cache_state(path: &Path) -> Value {
    match fs::symlink_metadata(path) {
        Ok(metadata) if metadata.is_file() => {
            json!({"path":path,"status":"captured","sha256":file_hash(path).ok()})
        }
        Ok(_) => json!({"path":path,"status":"unsupported-file-type"}),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            json!({"path":path,"status":"absent"})
        }
        Err(error) => json!({"path":path,"status":"unavailable","error":error.to_string()}),
    }
}
fn artifact_state(paths: &[(String, PathBuf)]) -> Value {
    let mut result = json!({});
    for (name, path) in paths {
        result[name.as_str()] = json!({"path":path,"sha256":file_hash(path).ok()});
    }
    result
}
fn metadata(program: &str, args: &[&str]) -> Value {
    match Command::new(program).args(args).output() {
        Ok(r) => {
            json!({"argv":std::iter::once(program).chain(args.iter().copied()).collect::<Vec<_>>(),"exit_code":r.status.code(),"stdout":String::from_utf8_lossy(&r.stdout),"stderr":String::from_utf8_lossy(&r.stderr)})
        }
        Err(e) => json!({"error":e.to_string()}),
    }
}
fn run(o: Options) -> Result<bool, String> {
    // Both directory and journal creation are exclusive; old evidence survives.
    fs::create_dir(&o.output)
        .map_err(|e| format!("Evidence directory {}: {e}", o.output.display()))?;
    let mut journal = ObservationJournal::create(&o.output.join("observations.jsonl"))?;
    journal.append("preflight",&json!({"baseline":{"path":o.baseline,"sha256":file_hash(&o.baseline).ok()},"candidate":{"path":o.candidate,"sha256":file_hash(&o.candidate).ok()},"coordinator":env::current_exe().ok(),"python_requested":o.python,"project":o.project,"output":o.output,"synthetic_pairs":o.pairs,"host_pairs":o.host_pairs,"journal":journal_identity(&o.output)}))?;
    let result = run_with_journal(o, &mut journal);
    match result {
        Ok(pass) => {
            journal.append("finished", &json!({"gate_pass":pass,"report_written":true}))?;
            Ok(pass)
        }
        Err(error) => {
            let _ = journal.append(
                "failure",
                &json!({"error":error,"final_report_not_confirmed":true}),
            );
            Err(error)
        }
    }
}
fn run_with_journal(mut o: Options, journal: &mut ObservationJournal) -> Result<bool, String> {
    let mut paths: Vec<(String, PathBuf)> = vec![
        ("baseline", o.baseline.clone()),
        ("candidate_binary", o.candidate.clone()),
        (
            "coordinator_binary",
            env::current_exe().map_err(|e| e.to_string())?,
        ),
        (
            "cargo_lock",
            o.project.join("docker/app/nzb-yenc-ffi/Cargo.lock"),
        ),
        (
            "cargo_toml",
            o.project.join("docker/app/nzb-yenc-ffi/Cargo.toml"),
        ),
        (
            "candidate_source",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/cpu_snapshot.rs"),
        ),
        (
            "candidate_cli",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/bin/nntmux-cpu-snapshot.rs"),
        ),
        (
            "coordinator_source",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/bin/benchmark-cpu-snapshot.rs"),
        ),
    ]
    .into_iter()
    .map(|(name, path)| (name.into(), path))
    .collect();
    let interpreter = metadata(&o.python, &["-c", "import sys; print(sys.executable)"]);
    let interpreter_path = interpreter["stdout"]
        .as_str()
        .map(str::trim)
        .filter(|p| !p.is_empty())
        .ok_or("Cannot resolve Python interpreter")?;
    let python_path = fs::canonicalize(interpreter_path).map_err(|e| e.to_string())?;
    o.python = python_path
        .to_str()
        .ok_or("Python executable is not UTF-8")?
        .to_owned();
    paths.push(("python_executable".into(), python_path));
    let cache_identity = metadata(
        &o.python,
        &[
            "-c",
            "import importlib.util,sys; print(importlib.util.cache_from_source(sys.argv[1]))",
            o.baseline.to_str().ok_or("Baseline path is not UTF-8")?,
        ],
    );
    let cache_text = cache_identity["stdout"]
        .as_str()
        .map(str::trim)
        .filter(|p| !p.is_empty())
        .ok_or("Cannot resolve baseline bytecode cache")?;
    if cache_identity["exit_code"] != 0 {
        return Err("Cannot resolve baseline bytecode cache".into());
    }
    let cache_path = PathBuf::from(cache_text);
    let cache_before = cache_state(&cache_path);
    if !matches!(cache_before["status"].as_str(), Some("captured" | "absent"))
        || (cache_before["status"] == "captured" && cache_before["sha256"].is_null())
    {
        return Err("Baseline bytecode cache cannot be pinned".into());
    }
    paths.push((
        "crate_lib_source".into(),
        o.project.join("docker/app/nzb-yenc-ffi/src/lib.rs"),
    ));
    fn inventory(directory: &Path, paths: &mut Vec<(String, PathBuf)>) -> Result<(), String> {
        let mut entries = fs::read_dir(directory)
            .map_err(|e| e.to_string())?
            .collect::<Result<Vec<_>, _>>()
            .map_err(|e| e.to_string())?;
        entries.sort_by_key(|entry| entry.path());
        for entry in entries {
            let kind = entry.file_type().map_err(|e| e.to_string())?;
            let path = entry.path();
            if kind.is_dir() {
                inventory(&path, paths)?;
            } else if kind.is_file()
                && path.extension().is_some_and(|e| e == "rs")
                && !paths.iter().any(|(_, existing)| existing == &path)
            {
                paths.push((
                    format!("crate_source_{}", hash(path.to_string_lossy().as_bytes())),
                    path,
                ));
            }
        }
        Ok(())
    }
    inventory(&o.project.join("docker/app/nzb-yenc-ffi/src"), &mut paths)?;
    let mut sources = json!({});
    for (name, path) in &paths {
        sources[name.as_str()] = json!({"path":path,"sha256":file_hash(path)?});
        journal.append(
            "source_identity",
            &json!({"name":name,"identity":sources[name.as_str()]}),
        )?;
    }
    let frozen_dir = o.output.join("frozen-artifacts");
    fs::create_dir(&frozen_dir).map_err(|e| e.to_string())?;
    for (name, path) in &paths {
        let destination = frozen_dir.join(name);
        fs::copy(path, &destination).map_err(|e| format!("Freeze {}: {e}", path.display()))?;
        if file_hash(&destination)? != sources[name.as_str()]["sha256"].as_str().unwrap() {
            return Err(format!("Artifact changed while freezing: {name}"));
        }
        sources[name.as_str()]["frozen_path"] = json!(destination);
    }
    if cache_before["status"] == "captured" {
        let destination = frozen_dir.join("baseline_bytecode_cache");
        fs::copy(&cache_path, &destination).map_err(|e| e.to_string())?;
        if file_hash(&destination)?.as_str() != cache_before["sha256"].as_str().unwrap() {
            return Err("Baseline cache changed while freezing".into());
        }
    }
    let host_proc_absent = match fs::symlink_metadata("/proc") {
        Ok(_) => false,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => true,
        Err(e) => return Err(format!("Cannot establish /proc presence: {e}")),
    };
    journal.append("setup",&json!({"stage":"synthetic-fixture-creation","sources":sources,"baseline_bytecode_cache":cache_before,"host_proc_absent":host_proc_absent}))?;
    let mut fixtures = vec![
        fixture(&o.output, "common-mounted", 2, false)?,
        fixture(&o.output, "rare-deep64", 64, false)?,
        fixture(&o.output, "rare-unavailable", 0, true)?,
    ];
    if host_proc_absent {
        fixtures.push(absent_fixture(Path::new("/proc")));
    }
    // The exact default absent-/proc CLI retains BOTH its original median
    // and p99 requirements; count that extra gate before any measurements.
    let family_size = synthetic_confidence_family_size(fixtures.len(), host_proc_absent);
    let schedule = orders(fixtures.len() * (o.pairs + 1));
    let mut protocol = json!({"observation_journal":journal_identity(&o.output),"host_proc_absent_at_start":host_proc_absent,"confidence_family":"Common median plus uncommon p99 gates, and an extra median gate for exact default absent-/proc CLI; other intervals diagnostic per-statistic95%","host_absent_median_ratio_ci_lower_gate":2.0,"host_absent_p99_ratio_ci_lower_gate":1.5,"seed":SEED,"pairs":o.pairs,"warmup_pairs_per_fixture":1,"orders_candidate_first":schedule,"order_sha256":hash(&serde_json::to_vec(&schedule).unwrap()),"timeout_seconds":5,"completion_poll_microseconds":250,"baseline_env":{"PYTHONDONTWRITEBYTECODE":"1"},"baseline_bytecode_cache":cache_before,"host_pairs_opt_in":o.host_pairs,"host_gate_scope":"Separate exact Linux host CLI screen; adoption_gate_pass is only the legacy synthetic/absent-proc gate","p99_estimator":"nearest rank ceil(0.99*N)","minimum_p99_pairs":1000,"common_median_ratio_ci_lower_gate":2.0,"rare_p99_ratio_ci_lower_gate":1.5,"timing_scope":"Instant immediately before process spawn through observed exit; CLI startup, reads, parsing, JSON serialization, exclusive output open/write/close included; correctness and hashing excluded","limitations":["Synthetic fixtures measure mounted Linux proc/cgroup evidence collection, not production CPU throughput or kernel CPU counters","Baseline synthetic fixtures use an importlib adapter to call original main with proc root; its startup/import overhead is included. Wholly absent /proc host stratum uses exact original CLI without adapter","250us polling adds completion observation delay and scheduling noise to both implementations; reported wall latency is not CPU usage","Each child process is fresh; one discarded warmup pair per fixture warms OS caches; measured results are warm-filesystem cold-process","On Linux live /proc gets separate sequential observations with changing counters; Linux production capacity remains unverified"]});
    protocol.as_object_mut().unwrap().extend(
        confidence_metadata(family_size)
            .as_object()
            .unwrap()
            .clone(),
    );
    let input_meta:Vec<_>=fixtures.iter().map(|f|json!({"name":f.name,"rare":f.rare,"proc_root":f.proc,"exact_default_cli":f.host,"negative_fixture_absence_required":f.host,"expected_exit":f.exit,"golden":f.golden,"input_sha256":f.inputs})).collect();
    let runtime = json!({"os":metadata("uname",&["-a"]),"hardware_macos":metadata("sysctl",&["-n","machdep.cpu.brand_string","hw.memsize","hw.ncpu"]),"hardware_linux":metadata("lscpu",&[]),"python":metadata(&o.python,&["-c","import sys,platform; print(sys.executable); print(sys.version); print(platform.platform())"]),"rustc":metadata("rustc",&["-Vv"]),"cargo":metadata("cargo",&["-V"]),"git_head":metadata("git",&["-C",o.project.to_str().unwrap(),"rev-parse","HEAD"]),"resource_limits":metadata("sh",&["-c","ulimit -a"]),"build_identity":"Executable hashes pinned; available compiler identity recorded; build command/profile supplied by executor, not inferred from binary"});
    journal.append(
        "protocol",
        &json!({"protocol":protocol,"sources":sources,"fixtures":input_meta,"runtime":runtime}),
    )?;
    fs::write(
        o.output.join("protocol.json"),
        serde_json::to_vec_pretty(
            &json!({"protocol":protocol,"sources":sources,"fixtures":input_meta,"runtime":runtime}),
        )
        .unwrap(),
    )
    .map_err(|e| e.to_string())?;
    let mut observations = vec![];
    let mut summary = json!({});
    let mut all_valid = true;
    let mut gates = true;
    for (i, f) in fixtures.iter().enumerate() {
        let dir = o.output.join(format!("samples-{}", f.name));
        fs::create_dir(&dir).map_err(|e| e.to_string())?;
        let mut b = vec![];
        let mut c = vec![];
        for pair in 0..=o.pairs {
            let order = if schedule[i * (o.pairs + 1) + pair] {
                ["candidate", "baseline"]
            } else {
                ["baseline", "candidate"]
            };
            let mut rows = json!({});
            for (order_index, which) in order.into_iter().enumerate() {
                let mut row = execute(
                    &o,
                    which,
                    if f.host { None } else { Some(&f.proc) },
                    &dir,
                    &format!("{pair:04}-{which}"),
                )?;
                let ok = messages_valid(&row, f.exit) && valid(&row["data"], Some(&f.golden));
                row["valid"] = json!(ok);
                row["fixture"] = json!(f.name);
                row["pair"] = json!(pair);
                row["warmup"] = json!(pair == 0);
                all_valid &= ok;
                rows[which] = row.clone();
                journal.append(
                    "observation",
                    &json!({"stratum":"synthetic","order_index":order_index,"row":row}),
                )?;
                observations.push(row);
            }
            if pair > 0 && rows["baseline"]["valid"] == true && rows["candidate"]["valid"] == true {
                b.push(rows["baseline"]["elapsed_ns"].as_f64().unwrap());
                c.push(rows["candidate"]["elapsed_ns"].as_f64().unwrap());
            }
        }
        let mut m =
            metrics(&b, &c, family_size, f.rare, f.host).unwrap_or(json!({"valid_pairs":0}));
        let pass = all_valid
            && b.len() == o.pairs
            && b.len() >= 1000
            && confidence_gate_passes(&m, f.rare, f.host);
        m["gate_pass"] = json!(pass);
        m["required_pairs"] = json!(o.pairs);
        gates &= pass;
        summary[f.name] = m;
        journal.append(
            "synthetic_summary",
            &json!({"fixture":f.name,"summary":summary[f.name]}),
        )?;
    }
    if !host_proc_absent {
        let host_dir = o.output.join("host");
        fs::create_dir(&host_dir).map_err(|e| e.to_string())?;
        for (order_index, which) in ["baseline", "candidate"].into_iter().enumerate() {
            let mut r = execute(&o, which, None, &host_dir, which)?;
            r["fixture"] = json!("real-host-observation");
            r["valid"] = json!(
                valid(&r["data"], None)
                    && messages_valid(
                        &r,
                        if r["data"]["os_cpu_usable"] == true {
                            0
                        } else {
                            1
                        }
                    )
            );
            all_valid &= r["valid"] == true;
            journal.append(
                "observation",
                &json!({"stratum":"real-host-observation","order_index":order_index,"row":r}),
            )?;
            observations.push(r);
        }
    }
    let mut host_comparison = if o.host_pairs == 0 {
        json!({"enabled":false,"summary":null,"host_gate_pass":false,"correctness_pass":null})
    } else if cfg!(target_os = "linux") && !host_proc_absent {
        let sources_before = artifact_state(&paths);
        let cache_before_host = cache_state(&cache_path);
        journal.append(
            "host_setup",
            &json!({"sources_before":sources_before,"bytecode_cache_before":cache_before_host}),
        )?;
        let mut result = host_run(&o, journal)?;
        result["sources_before"] = sources_before;
        result["sources_after"] = artifact_state(&paths);
        result["bytecode_cache_before"] = cache_before_host;
        result["bytecode_cache_after"] = cache_state(&cache_path);
        result["enabled"] = json!(true);
        result
    } else {
        json!({"enabled":true,"host_gate_pass":false,"correctness_pass":false,"observations":[],"summary":{"valid_pairs":0,"required_pairs":o.host_pairs,"outcome":"invalid-linux-proc-unavailable","invalid_observations":0},"failure":"Opt-in exact host comparison requires Linux with /proc; requested samples were not run"})
    };
    let mut changed = vec![];
    let cache_after = cache_state(&cache_path);
    if cache_before != cache_after {
        changed.push("baseline_bytecode_cache".to_owned());
    }
    let host_proc_absent_at_end = match fs::symlink_metadata("/proc") {
        Ok(_) => false,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => true,
        Err(_) => {
            changed.push("host_proc_presence_unreadable".to_owned());
            false
        }
    };
    if host_proc_absent != host_proc_absent_at_end {
        changed.push("host_proc_absence_changed".to_owned());
    }
    for (name, path) in &paths {
        if file_hash(path).ok().as_deref() != sources[name.as_str()]["sha256"].as_str() {
            changed.push(name.clone());
        }
    }
    if host_comparison["sources_before"].is_object() {
        for (name, _) in &paths {
            for endpoint in ["sources_before", "sources_after"] {
                if host_comparison[endpoint][name.as_str()]["sha256"]
                    != sources[name.as_str()]["sha256"]
                {
                    changed.push(format!("host-{endpoint}:{name}"));
                }
            }
        }
        if host_comparison["bytecode_cache_before"] != cache_before
            || host_comparison["bytecode_cache_after"] != cache_before
        {
            changed.push("host_bytecode_cache_changed".into());
        }
    }
    // Input fixtures are immutable as well as sources and binaries.
    for f in &fixtures {
        for (path, expected) in f.inputs.as_object().unwrap() {
            if file_hash(Path::new(path)).ok().as_deref() != expected.as_str() {
                changed.push(f.name.to_owned());
            }
        }
    }
    host_comparison["source_integrity_pass"] = json!(changed.is_empty());
    if !changed.is_empty() && o.host_pairs > 0 {
        host_comparison["host_gate_pass"] = json!(false);
        host_comparison["correctness_pass"] = json!(false);
        host_comparison["summary"]["gate_pass"] = json!(false);
        host_comparison["summary"]["outcome"] = json!("invalid-artifact-change");
    }
    journal.append("validation_summary",&json!({"summary":summary,"host_summary":host_comparison["summary"],"sources_after":artifact_state(&paths),"baseline_bytecode_cache_after":cache_after,"changed_artifacts":changed,"correctness_pass":all_valid&&changed.is_empty(),"adoption_gate_pass":gates&&all_valid&&changed.is_empty(),"host_gate_pass":host_comparison["host_gate_pass"],"overall_adoption_qualified":false}))?;
    let report = json!({"observation_journal":journal_identity(&o.output),"journal_records_flushed_before_report":journal.sequence,"host_comparison":host_comparison,"host_gate_pass":host_comparison["host_gate_pass"],"overall_adoption_qualified":false,"baseline_bytecode_cache_after":cache_after,"benchmark":"cpu-snapshot-cold-cli","protocol":protocol,"sources":sources,"fixtures":input_meta,"runtime":runtime,"summary":summary,"observations":observations,"host_proc_absent_at_end":host_proc_absent_at_end,"changed_artifacts":changed,"correctness_pass":all_valid&&changed.is_empty(),"adoption_gate_pass":gates&&all_valid&&changed.is_empty()});
    fs::write(
        o.output.join("report.json"),
        serde_json::to_vec_pretty(&report).unwrap(),
    )
    .map_err(|e| e.to_string())?;
    println!("{}",serde_json::to_string_pretty(&json!({"report":o.output.join("report.json"),"summary":summary,"correctness_pass":report["correctness_pass"],"adoption_gate_pass":report["adoption_gate_pass"],"host_summary":host_comparison["summary"],"host_gate_pass":report["host_gate_pass"],"overall_adoption_qualified":false})).unwrap());
    Ok(report["adoption_gate_pass"] == true
        && (o.host_pairs == 0 || report["host_gate_pass"] == true))
}
fn main() {
    let args: Vec<_> = env::args().skip(1).collect();
    if args.iter().any(|a| a == "--help" || a == "-h") {
        println!(
            "benchmark-cpu-snapshot --baseline=/absolute/original.py --output=/new/evidence-directory [--candidate=/release/nntmux-cpu-snapshot] [--project=ROOT] [--python=python3] [--pairs=1000] [--host-pairs=1000]"
        );
        return;
    }
    match parse(args).and_then(run) {
        Ok(true) => (),
        Ok(false) => std::process::exit(1),
        Err(e) => {
            eprintln!("{e}");
            std::process::exit(2)
        }
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[derive(Default)]
    struct JournalWriter {
        bytes: Vec<u8>,
        remaining: Option<usize>,
        flushes: usize,
        fail_flush: bool,
    }
    impl Write for JournalWriter {
        fn write(&mut self, bytes: &[u8]) -> std::io::Result<usize> {
            let length = self
                .remaining
                .map_or(bytes.len(), |remaining| remaining.min(bytes.len()));
            if length == 0 {
                return Err(std::io::Error::other("fixture write failure"));
            }
            self.bytes.extend_from_slice(&bytes[..length]);
            if let Some(remaining) = &mut self.remaining {
                *remaining -= length;
            }
            Ok(length)
        }
        fn flush(&mut self) -> std::io::Result<()> {
            self.flushes += 1;
            if self.fail_flush {
                Err(std::io::Error::other("fixture flush failure"))
            } else {
                Ok(())
            }
        }
    }
    fn test_journal() -> ObservationJournal<JournalWriter> {
        ObservationJournal {
            writer: JournalWriter::default(),
            sequence: 0,
            failed: false,
        }
    }
    #[test]
    fn journal_flushes_ordered_protocol_and_measurements_before_next_child() {
        let mut journal = test_journal();
        journal
            .append(
                "protocol",
                &json!({"source_sha256":"pinned","orders_candidate_first":[true]}),
            )
            .unwrap();
        journal.append("observation",&json!({"order_index":0,"row":{"implementation":"candidate","elapsed_ns":10,"valid":true}})).unwrap();
        journal.append("observation",&json!({"order_index":1,"row":{"implementation":"baseline","elapsed_ns":20,"valid":false}})).unwrap();
        assert_eq!(journal.writer.flushes, 3);
        let records: Vec<Value> = String::from_utf8(journal.writer.bytes)
            .unwrap()
            .lines()
            .map(|line| serde_json::from_str(line).unwrap())
            .collect();
        assert_eq!(
            records
                .iter()
                .map(|row| row["sequence"].as_u64().unwrap())
                .collect::<Vec<_>>(),
            [0, 1, 2]
        );
        assert_eq!(records[0]["event"], "protocol");
        assert_eq!(records[1]["details"]["row"]["implementation"], "candidate");
        assert_eq!(records[2]["details"]["row"]["elapsed_ns"], 20);
        assert_eq!(records[2]["details"]["row"]["valid"], false);
    }
    #[test]
    fn journal_partial_write_and_flush_failures_preserve_prior_records() {
        for partial_write in [true, false] {
            let mut journal = test_journal();
            journal
                .append("observation", &json!({"elapsed_ns":42,"valid":true}))
                .unwrap();
            let first = journal.writer.bytes.clone();
            if partial_write {
                journal.writer.remaining = Some(7);
            } else {
                journal.writer.fail_flush = true;
            }
            assert!(
                journal
                    .append("observation", &json!({"elapsed_ns":84,"valid":false}))
                    .is_err()
            );
            assert!(journal.writer.bytes.starts_with(&first));
            let first_record: Value = serde_json::from_slice(&first[..first.len() - 1]).unwrap();
            assert_eq!(first_record["details"]["elapsed_ns"], 42);
            assert_eq!(journal.sequence, 1);
            let retained = journal.writer.bytes.clone();
            assert!(journal.append("finished", &json!({})).is_err());
            assert_eq!(journal.writer.bytes, retained);
        }
    }
    #[test]
    fn journal_exclusive_creation_retains_existing_evidence() {
        let temp = PrivateFixture::new();
        let path = temp.0.join("observations.jsonl");
        let mut journal = ObservationJournal::create(&path).unwrap();
        journal.append("preflight", &json!({"owned":true})).unwrap();
        let original = fs::read(&path).unwrap();
        assert!(ObservationJournal::create(&path).is_err());
        assert_eq!(fs::read(&path).unwrap(), original);
    }
    #[test]
    fn journal_retains_preflight_and_setup_failure_without_final_report() {
        let temp = PrivateFixture::new();
        let baseline = temp.0.join("baseline.py");
        fs::write(&baseline, "# fixture baseline\n").unwrap();
        let output = temp.0.join("evidence");
        let options = parse(vec![
            format!("--baseline={}", baseline.display()),
            "--candidate=/usr/bin/false".into(),
            "--python=/usr/bin/false".into(),
            format!("--project={}", temp.0.display()),
            format!("--output={}", output.display()),
        ])
        .unwrap();
        assert!(run(options).is_err());
        let records: Vec<Value> = fs::read_to_string(output.join("observations.jsonl"))
            .unwrap()
            .lines()
            .map(|line| serde_json::from_str(line).unwrap())
            .collect();
        assert_eq!(records.len(), 2);
        assert_eq!(records[0]["event"], "preflight");
        assert!(records[0]["details"]["baseline"]["sha256"].is_string());
        assert_eq!(records[1]["event"], "failure");
        assert!(
            records[1]["details"]["error"]
                .as_str()
                .unwrap()
                .contains("Python interpreter")
        );
        assert!(!output.join("report.json").exists());
    }
    #[test]
    fn noisy_power_of_two_samples_have_noncollapsed_bootstrap_intervals() {
        let baseline: Vec<f64> = (1..=16).map(|value| value as f64).collect();
        let measured = metrics(&baseline, &[1.0; 16], 3, false, false).unwrap();
        let interval = &measured["median_ratio_ci95"];
        assert!(interval[0].as_f64().unwrap() < interval[1].as_f64().unwrap());
        let mut baseline = vec![1.0; 1024];
        baseline[1014..].fill(100.0);
        let measured = metrics(&baseline, &[1.0; 1024], 3, true, false).unwrap();
        let interval = &measured["p99_ratio_ci95"];
        assert!(interval[0].as_f64().unwrap() < interval[1].as_f64().unwrap());
    }
    #[test]
    fn opt_in_host_samples_preserve_legacy_default() {
        assert_eq!(parse_host_pairs("1").unwrap(), 1);
        assert_eq!(parse_host_pairs("1000").unwrap(), 1000);
        assert_eq!(parse_host_pairs("5000").unwrap(), 5000);
        for value in ["0", "5001", "-1", "NaN", ""] {
            assert!(parse_host_pairs(value).is_err(), "{value}");
        }
    }
    #[test]
    fn host_counters_retain_differences_and_require_bounded_witnesses() {
        let before = "cpu 10 0 5 30 7 0 0 0 0 0\ncpu0 10 0 5 30 7 0 0 0 0 0\nctxt 10\nbtime 100\nprocesses 5\nprocs_running 1\nprocs_blocked 0\n";
        let during = before
            .replace("cpu 10", "cpu 12")
            .replace("ctxt 10", "ctxt 11");
        let after = before
            .replace("cpu 10", "cpu 14")
            .replace("ctxt 10", "ctxt 15");
        assert!(raw_interval("stat", before, &during, &after).is_ok());
        assert!(
            raw_interval(
                "stat",
                before,
                &during.replace("ctxt 11", "ctxt 99"),
                &after
            )
            .is_err()
        );
        assert!(
            raw_interval(
                "stat",
                before,
                &during.replace("btime 100", "btime 101"),
                &after
            )
            .is_err()
        );
        // Linux iowait can decrease, and gauges have no monotonic contract.
        let gauges = during
            .replace("30 7", "30 3")
            .replace("procs_running 1", "procs_running 99");
        assert!(raw_interval("stat", before, &gauges, &after).is_ok());
        assert!(
            raw_interval(
                "stat",
                before,
                &during.replace("ctxt 11", "ctxt -1"),
                &after
            )
            .is_err()
        );
        assert!(raw_interval("stat", before, &during.replace("cpu0", "cpu1"), &after).is_err());
    }
    #[test]
    fn pressure_and_load_shapes_reject_nonfinite_values() {
        assert!(raw_interval("pressure_cpu", PRESSURE, PRESSURE, PRESSURE).is_ok());
        assert!(
            raw_interval(
                "pressure_cpu",
                PRESSURE,
                &PRESSURE.replace("total=54321", "total=99999"),
                PRESSURE
            )
            .is_err()
        );
        for bad in ["NaN", "inf", "-1"] {
            assert!(
                raw_interval(
                    "pressure_cpu",
                    PRESSURE,
                    &PRESSURE.replace("avg10=0.00", &format!("avg10={bad}")),
                    PRESSURE
                )
                .is_err()
            );
            assert!(
                raw_interval(
                    "loadavg",
                    "0 0 0 1/2 3",
                    &format!("{bad} 0 0 1/2 3"),
                    "0 0 0 1/2 3"
                )
                .is_err()
            );
        }
        assert!(raw_interval("loadavg", "0 0 0 1/2 3", "99 3 4 8/9 10", "0 0 0 1/2 3").is_ok());
        assert!(
            raw_interval(
                "cpu.stat",
                "usage_usec 10\n",
                "usage_usec 11\n",
                "usage_usec 20\n"
            )
            .is_ok()
        );
        assert!(
            raw_interval("cpu.max", "max 100000\n", "200000 100000\n", "max 100000\n").is_err()
        );
    }
    #[test]
    fn host_validation_checks_brackets_static_mapping_and_retains_raw_values() {
        let temp = PrivateFixture::new();
        let fixture = fixture(&temp.0, "host-validation-test", 2, false).unwrap();
        let mut before = nzbyenc::cpu_snapshot::snapshot(&fixture.proc);
        before["os"]["stat"]["path"] = json!("/proc/stat");
        before["os"]["pressure_cpu"]["path"] = json!("/proc/pressure/cpu");
        before["os"]["loadavg"]["path"] = json!("/proc/loadavg");
        before["monotonic_ns"] = json!(100);
        let mut during = before.clone();
        during["monotonic_ns"] = json!(101);
        during["os"]["stat"]["raw"] = json!(STAT.replace("cpu  102", "cpu  103"));
        let mut after = before.clone();
        after["monotonic_ns"] = json!(102);
        after["os"]["stat"]["raw"] = json!(STAT.replace("cpu  102", "cpu  104"));
        let validation = host_validation(&before, &during, &after);
        assert_eq!(validation["valid"], true, "{validation}");
        assert_eq!(
            validation["counter_witnesses"]["/proc/stat"]["raw_differs_from_before"],
            true
        );
        assert_ne!(during["os"]["stat"]["raw"], before["os"]["stat"]["raw"]);
        let mut bad = during.clone();
        bad["cgroup_v2"]["membership_path"] = json!("/wrong");
        assert_eq!(host_validation(&before, &bad, &after)["valid"], false);
        bad = during.clone();
        bad["monotonic_ns"] = json!(99);
        assert_eq!(host_validation(&before, &bad, &after)["valid"], false);
        bad = during.clone();
        bad["os"]["stat"]["status"] = json!("unavailable");
        assert_eq!(host_validation(&before, &bad, &after)["valid"], false);
    }
    #[test]
    fn exact_host_cli_and_child_failures_remain_reviewable() {
        let temp = PrivateFixture::new();
        let baseline = temp.0.join("original.py");
        fs::write(&baseline, "# original source\n").unwrap();
        let options = parse(vec![
            format!("--baseline={}", baseline.display()),
            "--candidate=/usr/bin/false".into(),
            format!("--project={}", temp.0.display()),
            format!("--output={}", temp.0.join("evidence").display()),
            "--python=/usr/bin/false".into(),
        ])
        .unwrap();
        assert_eq!(options.pairs, 1000);
        assert_eq!(options.host_pairs, 0);
        let row = execute(&options, "baseline", None, &temp.0, "failed-host").unwrap();
        assert_eq!(
            row["argv"],
            json!([
                "/usr/bin/false",
                baseline.canonicalize().unwrap(),
                "--output",
                temp.0.join("failed-host.json")
            ])
        );
        assert_eq!(row["exit_code"], 1);
        assert!(row["data"].is_null());
        assert!(row["output_error"].is_string());
        assert!(Path::new(row["stdout_path"].as_str().unwrap()).is_file());
        // A colliding stream is retained, not overwritten or silently excluded.
        let log = temp.0.join("collision.stdout");
        fs::write(&log, "owned witness").unwrap();
        let failed = execute(&options, "baseline", None, &temp.0, "collision").unwrap();
        assert!(failed["process_error"].is_string());
        assert_eq!(failed["elapsed_ns"], 0);
        assert_eq!(fs::read_to_string(log).unwrap(), "owned witness");
    }
    #[test]
    fn immutable_cache_identity_retains_absence_and_changes() {
        let temp = PrivateFixture::new();
        let path = temp.0.join("cache.pyc");
        let absent = cache_state(&path);
        assert_eq!(absent["status"], "absent");
        fs::write(&path, "a").unwrap();
        let first = cache_state(&path);
        assert_eq!(first["status"], "captured");
        fs::write(&path, "b").unwrap();
        assert_ne!(first, cache_state(&path));
    }
    #[test]
    fn independent_order() {
        assert_eq!(
            orders(8),
            vec![true, false, false, true, false, true, false, false]
        );
    }
    #[test]
    fn protocol_three_declares_separate_predeclared_confidence_families() {
        assert_eq!(synthetic_confidence_family_size(3, false), 3);
        assert_eq!(synthetic_confidence_family_size(4, true), 5);
        for size in [3, 5] {
            let protocol = confidence_metadata(size);
            assert_eq!(protocol["version"], 3);
            assert_eq!(protocol["order_rng"], "splitmix64-rejection");
            assert_eq!(protocol["bootstrap_rng"], "splitmix64-rejection");
            assert_eq!(protocol["bootstrap_resamples"], 4000);
            assert_eq!(protocol["bootstrap_seed"], SEED);
            assert_eq!(protocol["confidence_method"], "paired_bootstrap_bonferroni");
            assert_eq!(protocol["confidence_family_size"], size);
            assert_eq!(protocol["confidence_level"], 0.95);
        }
        let live = host_confidence_metadata();
        assert_eq!(live["version"], 3);
        assert_eq!(live["confidence_family_size"], 2);
        assert_eq!(live["median_ratio_ci_lower_gate"], 1.5);
        assert_eq!(live["p99_ratio_ci_lower_gate"], 1.5);
        assert_eq!(live["minimum_p99_pairs"], 1000);
        assert_eq!(live["bootstrap_resamples"], 4000);
        assert!(
            live["confidence_family"]
                .as_str()
                .unwrap()
                .contains("separate")
        );
    }
    #[test]
    fn live_host_uses_two_simultaneous_bounds_and_its_own_thresholds() {
        // A 1.75x effect clears both live-host gates but not absent-/proc's
        // preserved 2x median requirement.
        let baseline = vec![175.; 16];
        let candidate = vec![100.; 16];
        let live = host_metrics(&baseline, &candidate).unwrap();
        assert!(host_confidence_gate_passes(&live));
        assert!(!confidence_gate_passes(
            &metrics(&baseline, &candidate, 5, true, true).unwrap(),
            true,
            true
        ));
        assert!(live.get("gate_speedup_simultaneous_ci95").is_none());
        assert!(live.get("host_median_speedup_simultaneous_ci95").is_none());
        for field in [
            "live_median_speedup_simultaneous_ci95",
            "live_p99_speedup_simultaneous_ci95",
        ] {
            let mut invalid = live.clone();
            invalid[field] = json!([1.49, 1.75]);
            assert!(!host_confidence_gate_passes(&invalid));
            invalid[field] = Value::Null;
            assert!(!host_confidence_gate_passes(&invalid));
        }
        // Diagnostic pointwise intervals do not decide qualification.
        let mut diagnostic = live.clone();
        diagnostic["median_ratio_ci95"] = json!([1.0, 2.0]);
        diagnostic["p99_ratio_ci95"] = json!([1.0, 2.0]);
        assert!(host_confidence_gate_passes(&diagnostic));
    }
    #[test]
    fn live_host_bounds_use_two_gate_bonferroni_not_pointwise_or_absent_family() {
        let baseline: Vec<f64> = (1..=100).map(|v| v as f64).collect();
        let candidate = vec![1.; 100];
        let live = host_metrics(&baseline, &candidate).unwrap();
        let two = metric_intervals(&baseline, &candidate, 2).unwrap();
        assert_eq!(live["live_median_speedup_simultaneous_ci95"], two.median);
        assert_eq!(live["live_p99_speedup_simultaneous_ci95"], two.p99);
        for (decision, diagnostic) in [
            ("live_median_speedup_simultaneous_ci95", "median_ratio_ci95"),
            ("live_p99_speedup_simultaneous_ci95", "p99_ratio_ci95"),
        ] {
            assert!(live[decision][0].as_f64().unwrap() <= live[diagnostic][0].as_f64().unwrap());
            assert!(live[decision][1].as_f64().unwrap() >= live[diagnostic][1].as_f64().unwrap());
        }
        assert_ne!(
            live["live_median_speedup_simultaneous_ci95"],
            live["median_ratio_ci95"]
        );
    }
    #[test]
    fn bounded_generator_stays_in_range_for_power_of_two_and_other_bounds() {
        for bound in [1, 2, 3, 16, 1000, 1024, usize::MAX] {
            let mut first = SEED;
            let mut second = SEED;
            for _ in 0..128 {
                let value = bounded_index(&mut first, bound);
                assert!(value < bound);
                assert_eq!(value, bounded_index(&mut second, bound));
            }
        }
    }
    #[test]
    fn bootstrap_preserves_uncertainty_in_alternating_latency_population() {
        // A valid bootstrap does not force exactly half of every replicate into
        // each latency class. The old LCG modulo 1000 made this interval [2, 2].
        let baseline: Vec<f64> = (0..1000)
            .map(|i| if i % 2 == 0 { 1. } else { 3. })
            .collect();
        let candidate = vec![1.; 1000];
        let result = metrics(&baseline, &candidate, 4, false, false).unwrap();
        let interval = result["median_ratio_ci95"].as_array().unwrap();
        assert!(interval[0].as_f64().unwrap() < interval[1].as_f64().unwrap());
    }
    #[test]
    fn generator_accepts_zero_seed_and_matches_published_splitmix64_vector() {
        let mut state = 0;
        assert_eq!(next(&mut state), 0xe220_a839_7b1d_cdaf);
        assert_eq!(next(&mut state), 0x6e78_9e6a_a1b9_65f4);
    }
    #[test]
    fn bounded_index_rejects_the_incomplete_prefix() {
        // This seed makes the first SplitMix64 output zero. For bound 3, that
        // value is outside the evenly divisible accepted range and must retry.
        let mut state = 0u64.wrapping_sub(0x9e37_79b9_7f4a_7c15);
        assert_eq!(
            bounded_index(&mut state, 3),
            (0xe220_a839_7b1d_cdafu64 % 3) as usize
        );
        assert_eq!(state, 0x9e37_79b9_7f4a_7c15);
        assert_eq!(bounded_index(&mut state, 1), 0);
    }
    #[test]
    fn simultaneous_gate_interval_matches_the_declared_statistic_and_family() {
        let baseline: Vec<f64> = (1..=100).map(|v| v as f64).collect();
        let candidate = vec![1.; 100];
        let individual = metrics(&baseline, &candidate, 1, false, false).unwrap();
        assert_eq!(
            individual["gate_speedup_simultaneous_ci95"],
            individual["median_ratio_ci95"]
        );
        let family = metrics(&baseline, &candidate, 4, true, false).unwrap();
        assert_eq!(family["gate_statistic"], "p99_ratio");
        assert!(
            family["gate_speedup_simultaneous_ci95"][0]
                .as_f64()
                .unwrap()
                <= family["p99_ratio_ci95"][0].as_f64().unwrap()
        );
        assert!(
            family["gate_speedup_simultaneous_ci95"][1]
                .as_f64()
                .unwrap()
                >= family["p99_ratio_ci95"][1].as_f64().unwrap()
        );
        assert!(metrics(&baseline, &candidate, 0, false, false).is_none());
    }
    #[test]
    fn exact_default_host_keeps_both_median_and_p99_requirements() {
        let baseline = vec![175.; 1000];
        let candidate = vec![100.; 1000];
        let result = metrics(&baseline, &candidate, 5, true, true).unwrap();
        // Its p99 clears 1.5x, but the exact default CLI must also clear
        // the original 2x median gate. Fixing the RNG cannot relax that gate.
        assert!(confidence_gate_passes(&result, true, false));
        assert!(!confidence_gate_passes(&result, true, true));
        let mut passing = metrics(&vec![250.; 1000], &candidate, 5, true, true).unwrap();
        assert!(confidence_gate_passes(&passing, true, true));
        passing
            .as_object_mut()
            .unwrap()
            .remove("host_median_speedup_simultaneous_ci95");
        assert!(!confidence_gate_passes(&passing, true, true));
    }
    #[test]
    fn nearest_rank_and_median() {
        assert_eq!(percentile(&[9., 1., 2., 7.], 0.5), Some(2.));
        assert_eq!(percentile(&[9., 1., 2., 7.], 0.99), Some(9.));
        assert_eq!(percentile(&[], 0.99), None);
        assert_eq!(percentile(&[f64::NAN], 0.99), None);
    }
    #[test]
    fn metrics_reject_invalid_and_preserve_p99_definition() {
        assert!(metrics(&[], &[], 1, false, false).is_none());
        assert!(metrics(&[1.], &[1., 2.], 1, false, false).is_none());
        assert!(metrics(&[1.], &[0.], 1, false, false).is_none());
        assert!(metrics(&[f64::INFINITY], &[1.], 1, false, false).is_none());
        let m = metrics(&[10., 20., 30., 100.], &[1., 2., 3., 20.], 3, true, false).unwrap();
        assert_eq!(m["p99_ratio"], 5.);
        assert_eq!(m["paired_median_ratio"], 10.);
    }
    struct PrivateFixture(PathBuf);
    impl PrivateFixture {
        fn new() -> Self {
            Self::new_at(
                std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap()
                    .as_nanos(),
            )
        }
        fn new_at(timestamp: u128) -> Self {
            static NEXT: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
            loop {
                let serial = NEXT.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                let path = env::temp_dir().join(format!(
                    "nntmux-cpu-benchmark-{}-{timestamp}-{serial}",
                    std::process::id()
                ));
                match fs::create_dir(&path) {
                    Ok(()) => return Self(path),
                    Err(e) if e.kind() == std::io::ErrorKind::AlreadyExists => continue,
                    Err(e) => panic!("Cannot create private fixture {}: {e}", path.display()),
                }
            }
        }
    }
    #[test]
    fn fixtures_with_identical_clock_readings_have_distinct_owned_paths() {
        let a = PrivateFixture::new_at(424242);
        let b = PrivateFixture::new_at(424242);
        assert_ne!(a.0, b.0);
        let owned = b.0.clone();
        drop(a);
        assert!(owned.is_dir());
    }
    impl Drop for PrivateFixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    #[test]
    fn independent_fixtures_match_collector_without_normalizing_raw_or_status() {
        let temp = PrivateFixture::new();
        for (name, depth, bad, count) in [
            ("common-test", 2, false, 3),
            ("deep-test", 64, false, 65),
            ("bad-test", 0, true, 0),
        ] {
            let f = fixture(&temp.0, name, depth, bad).unwrap();
            let data = nzbyenc::cpu_snapshot::snapshot(&f.proc);
            assert!(valid(&data, Some(&f.golden)), "{name}: {data}");
            assert_eq!(
                data["cgroup_v2"]["ancestors"]
                    .as_array()
                    .map_or(0, Vec::len),
                count
            );
            let mut wrong = data.clone();
            wrong["os"]["stat"]["raw"] = json!("changed");
            assert!(!valid(&wrong, Some(&f.golden)));
            let mut wrong = data.clone();
            wrong["os"]["stat"]["status"] = json!("unavailable");
            assert!(!valid(&wrong, Some(&f.golden)));
            let mut wrong = data.clone();
            wrong["monotonic_ns"] = json!(0);
            assert!(!valid(&wrong, Some(&f.golden)));
        }
    }
    #[test]
    fn fixture_hashes_pin_actual_bytes_and_invalid_options_fail() {
        let temp = PrivateFixture::new();
        let f = fixture(&temp.0, "pin-test", 2, false).unwrap();
        for (path, expected) in f.inputs.as_object().unwrap() {
            assert_eq!(
                file_hash(Path::new(path)).unwrap(),
                expected.as_str().unwrap()
            );
        }
        let path = f.proc.join("stat");
        let before = file_hash(&path).unwrap();
        fs::write(&path, "cpu 9 9 9 9\n").unwrap();
        assert_ne!(file_hash(&path).unwrap(), before);
        assert!(parse(vec!["--pairs=0".into()]).is_err());
        assert!(parse(vec!["--unknown=x".into()]).is_err());
        assert!(parse(vec!["--baseline=relative.py".into()]).is_err());
    }
    #[test]
    fn policy_errors_and_child_messages_are_correctness_evidence() {
        let mut policy = json!({"error":"wrong policy","membership":{"path":"/missing","status":"unavailable","error":"OS-specific"}});
        normalize(&mut policy);
        assert_eq!(policy["error"], "wrong policy");
        assert_eq!(policy["membership"]["error"], "ERROR_PRESENT");
        let mut row = json!({"exit_code":1,"timed_out":false,"process_error":null,"stdout":"","stderr":"CPU evidence retained but no usable OS CPU counters\n"});
        assert!(messages_valid(&row, 1));
        row["stderr"] = json!("unexpected\n");
        assert!(!messages_valid(&row, 1));
        row["stderr"] = json!("");
        row["exit_code"] = json!(0);
        assert!(messages_valid(&row, 0));
        row["stdout"] = json!("unexpected");
        assert!(!messages_valid(&row, 0));
    }

    #[test]
    fn wholly_absent_proc_has_independent_golden() {
        let temp = PrivateFixture::new();
        let absent = temp.0.join("absent-proc");
        let f = absent_fixture(&absent);
        let data = nzbyenc::cpu_snapshot::snapshot(&absent);
        assert!(f.host);
        assert_eq!(f.exit, 1);
        assert!(f.inputs.as_object().unwrap().is_empty());
        assert!(valid(&data, Some(&f.golden)), "{data}");
        let mut wrong = data.clone();
        wrong["cgroup_v2"]["error"] = json!("wrong policy");
        assert!(!valid(&wrong, Some(&f.golden)));
    }
}
