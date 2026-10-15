use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    env, fs,
    path::{Path, PathBuf},
    process::{Command, Stdio},
    thread,
    time::{Duration, Instant},
};
const SEED: u64 = 20261014;
const BOOTSTRAP_RESAMPLES: usize = 4000;
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
            _ => return Err(format!("Unknown option {k}")),
        }
    }
    if !o.baseline.is_absolute() || !o.baseline.is_file() {
        return Err("--baseline requires an absolute original Python source file".into());
    }
    if !(1..=5000).contains(&o.pairs) || o.output.as_os_str().is_empty() {
        return Err("--pairs must be 1..5000; --output must name a new evidence directory".into());
    }
    o.project = fs::canonicalize(o.project).map_err(|e| e.to_string())?;
    o.candidate = fs::canonicalize(o.candidate).map_err(|e| e.to_string())?;
    if !o.output.is_absolute() {
        o.output = env::current_dir()
            .map_err(|e| e.to_string())?
            .join(o.output)
    }
    Ok(o)
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
fn metrics(b: &[f64], c: &[f64], family_size: usize, rare: bool, host: bool) -> Option<Value> {
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
    let gate = if rare { &tail } else { &med };
    let alpha = 0.05 / family_size as f64 / 2.;
    let mut result = json!({"gate_statistic":if rare { "p99_ratio" } else { "median_ratio" },"gate_speedup_simultaneous_ci95":[percentile(gate,alpha),percentile(gate,1.-alpha)],"valid_pairs":b.len(),"baseline_median_ns":bm,"candidate_median_ns":cm,"median_ratio":bm/cm,"paired_median_ratio":median(&paired),"speedup_percent":100.*(bm/cm-1.),"baseline_p99_ns":bp,"candidate_p99_ns":cp,"p99_ratio":bp/cp,"median_ratio_ci95":[percentile(&med,0.025),percentile(&med,0.975)],"p99_ratio_ci95":[percentile(&tail,0.025),percentile(&tail,0.975)],"expected_samples_above_p99":b.len() as f64*0.01,"p99_sample_sufficient":b.len()>=1000});
    if host {
        result["host_median_speedup_simultaneous_ci95"] =
            json!([percentile(&med, alpha), percentile(&med, 1. - alpha)]);
    }
    Some(result)
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
    command
        .stdout(Stdio::from(
            fs::File::create(&stdout).map_err(|e| e.to_string())?,
        ))
        .stderr(Stdio::from(
            fs::File::create(&stderr).map_err(|e| e.to_string())?,
        ));
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
    let data = fs::read(&output)
        .ok()
        .and_then(|b| serde_json::from_slice::<Value>(&b).ok());
    Ok(
        json!({"implementation":which,"argv":argv,"elapsed_ns":elapsed,"exit_code":status.as_ref().ok().and_then(|s|s.code()),"process_error":status.err().map(|e|e.to_string()),"timed_out":timeout,"output_path":output,"output_sha256":file_hash(&output).ok(),"data":data,"stdout_path":stdout,"stderr_path":stderr,"stdout":fs::read_to_string(&stdout).ok(),"stderr":fs::read_to_string(&stderr).ok()}),
    )
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
    // create_dir is intentionally exclusive: no existing evidence is overwritten.
    fs::create_dir(&o.output)
        .map_err(|e| format!("Evidence directory {}: {e}", o.output.display()))?;
    let mut paths = vec![
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
    ];
    let interpreter = metadata(&o.python, &["-c", "import sys; print(sys.executable)"]);
    let interpreter_path = interpreter["stdout"]
        .as_str()
        .map(str::trim)
        .filter(|p| !p.is_empty())
        .ok_or("Cannot resolve Python interpreter")?;
    paths.push((
        "python_executable",
        fs::canonicalize(interpreter_path).map_err(|e| e.to_string())?,
    ));
    paths.push((
        "crate_lib_source",
        o.project.join("docker/app/nzb-yenc-ffi/src/lib.rs"),
    ));
    let mut sources = json!({});
    for (name, path) in &paths {
        sources[*name] = json!({"path":path,"sha256":file_hash(path)?});
    }
    let host_proc_absent = match fs::symlink_metadata("/proc") {
        Ok(_) => false,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => true,
        Err(e) => return Err(format!("Cannot establish /proc presence: {e}")),
    };
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
    let family_size = fixtures.len() + usize::from(host_proc_absent);
    let schedule = orders(fixtures.len() * (o.pairs + 1));
    let protocol = json!({"version":3,"host_proc_absent_at_start":host_proc_absent,"order_rng":"splitmix64-rejection","bootstrap_rng":"splitmix64-rejection","confidence_method":"paired_bootstrap_bonferroni","confidence_family_size":family_size,"confidence_family":"Common median plus uncommon p99 gates, and an extra median gate for exact default absent-/proc CLI; other intervals diagnostic per-statistic95%","host_absent_median_ratio_ci_lower_gate":2.0,"host_absent_p99_ratio_ci_lower_gate":1.5,"seed":SEED,"pairs":o.pairs,"warmup_pairs_per_fixture":1,"orders_candidate_first":schedule,"order_sha256":hash(&serde_json::to_vec(&schedule).unwrap()),"timeout_seconds":5,"completion_poll_microseconds":250,"bootstrap_resamples":BOOTSTRAP_RESAMPLES,"bootstrap_seed":SEED,"p99_estimator":"nearest rank ceil(0.99*N)","minimum_p99_pairs":1000,"common_median_ratio_ci_lower_gate":2.0,"rare_p99_ratio_ci_lower_gate":1.5,"timing_scope":"Instant immediately before process spawn through observed exit; CLI startup, reads, parsing, JSON serialization, exclusive output open/write/close included; correctness and hashing excluded","limitations":["Synthetic fixtures measure mounted Linux proc/cgroup evidence collection, not production CPU throughput or kernel CPU counters","Baseline synthetic fixtures use an importlib adapter to call original main with proc root; its startup/import overhead is included. Wholly absent /proc host stratum uses exact original CLI without adapter","250us polling adds completion observation delay and scheduling noise to both implementations; reported wall latency is not CPU usage","Each child process is fresh; one discarded warmup pair per fixture warms OS caches; measured results are warm-filesystem cold-process","On Linux live /proc gets separate sequential observations with changing counters; Linux production capacity remains unverified"]});
    let input_meta:Vec<_>=fixtures.iter().map(|f|json!({"name":f.name,"rare":f.rare,"proc_root":f.proc,"exact_default_cli":f.host,"negative_fixture_absence_required":f.host,"expected_exit":f.exit,"golden":f.golden,"input_sha256":f.inputs})).collect();
    let runtime = json!({"os":metadata("uname",&["-a"]),"hardware_macos":metadata("sysctl",&["-n","machdep.cpu.brand_string","hw.memsize","hw.ncpu"]),"hardware_linux":metadata("lscpu",&[]),"python":metadata(&o.python,&["-c","import sys,platform; print(sys.executable); print(sys.version); print(platform.platform())"]),"rustc":metadata("rustc",&["-Vv"]),"cargo":metadata("cargo",&["-V"]),"git_head":metadata("git",&["-C",o.project.to_str().unwrap(),"rev-parse","HEAD"]),"resource_limits":metadata("sh",&["-c","ulimit -a"]),"build_identity":"Executable hashes pinned; available compiler identity recorded; build command/profile supplied by executor, not inferred from binary"});
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
            for which in order {
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
    }
    if !host_proc_absent {
        let host_dir = o.output.join("host");
        fs::create_dir(&host_dir).map_err(|e| e.to_string())?;
        for which in ["baseline", "candidate"] {
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
            observations.push(r);
        }
    }
    let mut changed = vec![];
    let host_proc_absent_at_end = match fs::symlink_metadata("/proc") {
        Ok(_) => false,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => true,
        Err(_) => {
            changed.push("host_proc_presence_unreadable");
            false
        }
    };
    if host_proc_absent != host_proc_absent_at_end {
        changed.push("host_proc_absence_changed");
    }
    for (name, path) in &paths {
        if file_hash(path).ok().as_deref() != sources[*name]["sha256"].as_str() {
            changed.push(*name);
        }
    }
    // Input fixtures are immutable as well as sources and binaries.
    for f in &fixtures {
        for (path, expected) in f.inputs.as_object().unwrap() {
            if file_hash(Path::new(path)).ok().as_deref() != expected.as_str() {
                changed.push(f.name);
            }
        }
    }
    let report = json!({"benchmark":"cpu-snapshot-cold-cli","protocol":protocol,"sources":sources,"fixtures":input_meta,"runtime":runtime,"summary":summary,"observations":observations,"host_proc_absent_at_end":host_proc_absent_at_end,"changed_artifacts":changed,"correctness_pass":all_valid&&changed.is_empty(),"adoption_gate_pass":gates&&all_valid&&changed.is_empty()});
    fs::write(
        o.output.join("report.json"),
        serde_json::to_vec_pretty(&report).unwrap(),
    )
    .map_err(|e| e.to_string())?;
    println!("{}",serde_json::to_string_pretty(&json!({"report":o.output.join("report.json"),"summary":summary,"correctness_pass":report["correctness_pass"],"adoption_gate_pass":report["adoption_gate_pass"]})).unwrap());
    Ok(report["adoption_gate_pass"] == true)
}
fn main() {
    let args: Vec<_> = env::args().skip(1).collect();
    if args.iter().any(|a| a == "--help" || a == "-h") {
        println!(
            "benchmark-cpu-snapshot --baseline=/absolute/original.py --output=/new/evidence-directory [--candidate=/release/nntmux-cpu-snapshot] [--project=ROOT] [--python=python3] [--pairs=1000]"
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
    #[test]
    fn independent_order() {
        assert_eq!(
            orders(8),
            vec![true, false, false, true, false, true, false, false]
        );
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
