use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    env, fs,
    io::{BufWriter, Read, Write},
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    process::{Command, Stdio},
    thread,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};
const SEED: u64 = 20261014;
const CANDIDATE_STATUS: &str = "experimental_withdrawn_from_production";
const BOOTSTRAP_RESAMPLES: usize = 2000;
const MINIMUM_TAIL_PAIRS: usize = 1000;
const BOOTSTRAP_RNG: &str =
    "LCG state with SplitMix64 output finalizer and rejection-sampled bounded indices";
const TIMING_SCOPE: &str = "Fresh PHP process: cold complete public operation then one repeated complete operation. PHP process startup, fixture construction, autoload and artifact hashing excluded; accumulator allocation, FFI, spill I/O, cleanup and every Rust worker startup included. fromContents includes XML parsing; fromXml receives pre-parsed XML. Incremental constructor/add/value includes app container resolution.";
const FIXTURES: [(&str, usize, &str, &str); 5] = [
    (
        "ids16",
        16,
        "fromMessageIds",
        "47942c96000adb861d7efd86b38c7964e5e5c513",
    ),
    (
        "ids4096",
        4096,
        "fromMessageIds",
        "494f4be1d91ac40fe6e9be7783b69ba1efa5682b",
    ),
    (
        "ids10000-unique-plus-duplicates",
        20000,
        "fromMessageIds",
        "99a07181cd05b4802c7ff7f51f7daa14ef985642",
    ),
    (
        "ids50000",
        50000,
        "fromMessageIds",
        "1b4639517e597662cff2fcbbae6ddab890d8c376",
    ),
    (
        "xml10000",
        10000,
        "fromContents",
        "99a07181cd05b4802c7ff7f51f7daa14ef985642",
    ),
];
const FULL_FIXTURES: [(&str, usize, &str, &str); 4] = [
    (
        "xml10000-fromXml",
        10000,
        "fromXml",
        "99a07181cd05b4802c7ff7f51f7daa14ef985642",
    ),
    (
        "incremental16",
        16,
        "incremental",
        "47942c96000adb861d7efd86b38c7964e5e5c513",
    ),
    (
        "ids16-max255",
        16,
        "fromMessageIds",
        "e968226bebf6b6b837158df2f27d9a9f6f6b2afb",
    ),
    (
        "incremental25000-max255-spill",
        25000,
        "incremental",
        "5e9ae91ba33900114c8bcceab353411d56935833",
    ),
];
fn uncommon(fixture: &str) -> bool {
    fixture == "xml10000-fromXml" || fixture.contains("max255")
}
// These reviewed archived callers contain no PHP fallback. A source change requires a new
// native-path audit and deliberate pin update, rather than silent attribution.
const NATIVE_CLASS_SHA256: &str =
    "d43ccd3c5528d56be1c93791745243fc057f514fbdde85877259cf0679627374";
const NATIVE_WORKER_SHA256: &str =
    "241a70dd956ff2f23fea70c57ea7b3bd85be22784ef31ce8f967eee628f423c5";
const NATIVE_ASSERTIONS: &str = r#"
function assertFingerprintSource(string $class,string $source,string $expectedHash): void {
    $resolved=realpath($source);
    if($resolved===false || realpath((new ReflectionClass($class))->getFileName())!==$resolved || hash_file('sha256',$resolved)!==$expectedHash) throw new RuntimeException('Unreviewed native fingerprint caller source');
}
function assertFingerprintBackend(object $accumulator,bool $ffi,string $library,string $worker): array {
    $class=get_class($accumulator);
    $activeLibrary=(new ReflectionProperty($class,'library'))->getValue($accumulator);
    $state=(new ReflectionProperty($class,'state'))->getValue($accumulator);
    $activeWorker=(new ReflectionProperty($class,'worker'))->getValue($accumulator);
    if($ffi) {
        $libraries=(new ReflectionProperty($class,'libraries'))->getValue();
        if(!$activeLibrary instanceof FFI || !$state instanceof FFI\CData || FFI::isNull($state) || $activeWorker!==null || ($libraries[getmypid().':'.realpath($library)]??null)!==$activeLibrary) throw new RuntimeException('Native FFI state was not initialized from pinned library');
        return ['backend'=>'rust-ffi','state_nonnull'=>true,'library_cache_identity'=>true];
    }
    if($activeLibrary!==null || $state!==null || !is_object($activeWorker)) throw new RuntimeException('Native worker backend was not selected');
    $process=(new ReflectionProperty(get_class($activeWorker),'process'))->getValue($activeWorker);
    if(!is_resource($process)) throw new RuntimeException('Native worker process is unavailable');
    $status=proc_get_status($process);
    if(!$status['running'] || realpath($status['command'])!==realpath($worker)) throw new RuntimeException('Native worker process does not match pinned binary');
    return ['backend'=>'rust-worker','process_running'=>true,'worker_path'=>realpath($status['command'])];
}
"#;
fn generated_driver() -> String {
    format!(
        "const NATIVE_CLASS_SHA256='{NATIVE_CLASS_SHA256}'; const NATIVE_WORKER_SHA256='{NATIVE_WORKER_SHA256}';{NATIVE_ASSERTIONS}{DRIVER}"
    )
}
// Measurement calls the archived integration through its original public API.
// Production uses PHP. Dynamic object inspection runs in a separate proof
// process, never inside an operation timer.
const DRIVER: &str = r#"
[$root,$baseline,$implementation,$fixture,$count,$method,$expected]=array_slice($argv,1);
$ids=[]; for($i=($fixture==='ids10000-unique-plus-duplicates'?10000:(int)$count)-1;$i>=0;$i--) $ids[]=sprintf('fp-%05d@bench.invalid',$i);
if(str_contains($fixture,'max255')) $ids=array_map(static fn($id)=>str_pad($id,255,'x'),$ids);
if($fixture==='ids10000-unique-plus-duplicates') $ids=[...$ids,...array_map(static fn($id)=>'"<'.$id.'>"',$ids)];
$payload=$ids; $hash=hash_init('sha256');
if($method==='fromContents'||$method==='fromXml') { $payload='<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file><segments>'; foreach($ids as $id) $payload.='<segment bytes="1">'.$id.'</segment>'; $payload.='</segments></file></nzb>'; hash_update($hash,$payload); if($method==='fromXml') $payload=simplexml_load_string($payload,SimpleXMLElement::class,LIBXML_NONET); }
else foreach($ids as $id) hash_update($hash,pack('N',strlen($id)).$id);
$class='App\\Services\\Nzb\\NzbArticleFingerprint';
if($implementation==='baseline') require $baseline;
else require $root.'/tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php';
require $root.'/vendor/autoload.php'; if(!class_exists($class)) throw new RuntimeException('Fingerprint class missing');
if($implementation==='candidate') foreach([
    [$class,$root.'/tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php',NATIVE_CLASS_SHA256],
    ['App\\Services\\Nzb\\RustArticleFingerprintWorker',$root.'/app/Services/Nzb/RustArticleFingerprintWorker.php',NATIVE_WORKER_SHA256],
] as [$nativeClass,$source,$sourceHash]) assertFingerprintSource($nativeClass,$source,$sourceHash);
$library=$root.'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.(PHP_OS_FAMILY==='Darwin'?'dylib':'so');
$worker=$root.'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint';
Illuminate\Container\Container::getInstance()->instance('config',new Illuminate\Config\Repository(['yenc'=>['native_library'=>$library]]));
$ffi=extension_loaded('FFI')&&!in_array(strtolower((string)ini_get('ffi.enable')),['','0','false','off'],true);
if($implementation==='candidate'&&$ffi&&function_exists('proc_open')) throw new RuntimeException('FFI measurement must disable worker startup');
$artifact=$ffi?$library:$worker; $artifactHash=is_file($artifact)?hash_file('sha256',$artifact):null;
if($implementation==='candidate'&&!is_string($artifactHash)) throw new RuntimeException('Runtime artifact missing');
if($implementation==='candidate'&&!$ffi) foreach([$root.'/storage/app/nntmux-native/nntmux-article-fingerprint','/usr/local/bin/nntmux-article-fingerprint'] as $alternative) if(is_file($alternative)&&is_executable($alternative)&&realpath($alternative)!==realpath($worker)) throw new RuntimeException('Unpinned fallback worker exists');
$phpBinary=realpath(PHP_BINARY); $phpHash=hash_file('sha256',$phpBinary); $temp=sys_get_temp_dir(); $tempResolved=realpath($temp); $tempStat=stat($temp);
if(($argv[8]??'')==='native-proof') {
    $accumulator=app($class);
    $before=assertFingerprintBackend($accumulator,$ffi,$library,$worker);
    foreach($ids as $id) $accumulator->add($id);
    $result=$accumulator->value();
    $after=assertFingerprintBackend($accumulator,$ffi,$library,$worker);
    unset($accumulator);
    if($result!==$expected||hash_file('sha256',$artifact)!==$artifactHash) throw new RuntimeException('Native proof result or artifact changed');
    echo json_encode(['native_execution_proven'=>true,'backend'=>$before['backend'],'before'=>$before,'after'=>$after,'fingerprint'=>$result,'artifact_path'=>realpath($artifact),'artifact_sha256'=>$artifactHash,'proc_open_available'=>function_exists('proc_open'),'ffi_enable'=>ini_get('ffi.enable'),'scope'=>'Separate process: actual constructor/add/value state inspection; measured static and incremental operations use reviewed native-only source with alternate backend disabled'],JSON_THROW_ON_ERROR);
    return;
}
$measure=static function() use($class,$method,$payload) { $start=hrtime(true); if($method==='incremental') { $accumulator=app($class); foreach($payload as $id) $accumulator->add($id); $result=$accumulator->value(); unset($accumulator); } else $result=$class::$method($payload); return [$result,hrtime(true)-$start]; };
[$cold,$coldTime]=$measure(); [$hot,$hotTime]=$measure();
if($implementation==='candidate') { $libraries=(new ReflectionProperty($class,'libraries'))->getValue(); $used=isset($libraries[getmypid().':'.realpath($library)]); if($used!==$ffi||($ffi&&count($libraries)!==1)||hash_file('sha256',$artifact)!==$artifactHash) throw new RuntimeException('Pinned backend or artifact changed'); }
if(realpath(PHP_BINARY)!==$phpBinary||hash_file('sha256',$phpBinary)!==$phpHash||sys_get_temp_dir()!==$temp||realpath($temp)!==$tempResolved) throw new RuntimeException('PHP executable or temporary directory changed');
echo json_encode(['fixture_count'=>count($ids),'fixture_sha256'=>hash_final($hash),'cold_elapsed_ns'=>$coldTime,'elapsed_ns'=>$hotTime,'cold_fingerprint'=>$cold,'fingerprint'=>$hot,'correct'=>$cold===$expected&&$hot===$expected,'backend'=>$implementation==='baseline'?'php':($ffi?'rust-ffi':'rust-worker'),'native_only_source_verified'=>$implementation==='candidate','proc_open_available'=>function_exists('proc_open'),'ffi_enable'=>ini_get('ffi.enable'),'artifact_path'=>$implementation==='candidate'?realpath($artifact):null,'artifact_sha256'=>$implementation==='candidate'?$artifactHash:null,'php_binary'=>$phpBinary,'php_binary_sha256'=>$phpHash,'php_temp_directory'=>['path'=>$temp,'canonical_path'=>$tempResolved,'device'=>$tempStat['dev'],'inode'=>$tempStat['ino']]],JSON_THROW_ON_ERROR);
"#;
#[derive(Debug)]
struct Options {
    baseline: PathBuf,
    project: PathBuf,
    backend: String,
    php: String,
    pairs: usize,
    coverage: String,
    tail_pairs: Option<usize>,
    output: Option<PathBuf>,
}
fn parse(args: Vec<String>) -> Result<Options, String> {
    let mut o = Options {
        baseline: PathBuf::new(),
        project: env::current_dir().map_err(|e| e.to_string())?,
        backend: "ffi".into(),
        php: "php".into(),
        pairs: 9,
        coverage: "screen".into(),
        tail_pairs: None,
        output: None,
    };
    for arg in args {
        let (key, value) = arg
            .strip_prefix("--")
            .and_then(|a| a.split_once('='))
            .ok_or_else(|| format!("Invalid argument: {arg}"))?;
        match key {
            "baseline" => o.baseline = value.into(),
            "project" => o.project = value.into(),
            "backend" if ["ffi", "worker"].contains(&value) => o.backend = value.into(),
            "php" if !value.is_empty() => o.php = value.into(),
            "output" if !value.is_empty() => o.output = Some(value.into()),
            "coverage" if ["screen", "full"].contains(&value) => o.coverage = value.into(),
            "tail-pairs" => o.tail_pairs = Some(value.parse().map_err(|_| "Invalid tail pairs")?),
            "pairs" => {
                o.pairs = value.parse().map_err(|_| "Invalid pairs")?;
            }
            _ => return Err(format!("Unknown or invalid option: {key}")),
        }
    }
    let maximum_pairs = if o.coverage == "full" { 5000 } else { 100 };
    if !(1..=maximum_pairs).contains(&o.pairs) {
        return Err(format!("Pairs must be 1..{maximum_pairs}"));
    }
    if o.tail_pairs
        .is_some_and(|n| !(MINIMUM_TAIL_PAIRS..=5000).contains(&n))
        || (o.tail_pairs.is_some() && o.coverage != "full")
    {
        return Err("--tail-pairs requires full coverage and 1000..5000 pairs".into());
    }
    if !o.baseline.is_absolute() || !o.baseline.is_file() {
        return Err("--baseline must be an absolute readable PHP file".into());
    }
    o.project = fs::canonicalize(&o.project).map_err(|e| e.to_string())?;
    Ok(o)
}
fn median(mut values: Vec<f64>) -> Option<f64> {
    if values.is_empty() || values.iter().any(|v| !v.is_finite() || *v <= 0.) {
        return None;
    }
    values.sort_by(f64::total_cmp);
    let m = values.len() / 2;
    Some(if values.len() % 2 == 1 {
        values[m]
    } else {
        (values[m - 1] + values[m]) / 2.
    })
}
fn percentile(values: &[f64], p: f64) -> Option<f64> {
    if !(0.0..=1.0).contains(&p)
        || values.is_empty()
        || values.iter().any(|v| !v.is_finite() || *v <= 0.)
    {
        return None;
    }
    let mut sorted = values.to_vec();
    sorted.sort_by(f64::total_cmp);
    Some(sorted[((p * sorted.len() as f64).ceil() as usize).saturating_sub(1)])
}
fn next(state: &mut u64) -> u64 {
    *state = state
        .wrapping_mul(6364136223846793005)
        .wrapping_add(1442695040888963407);
    *state
}
fn bootstrap_index(state: &mut u64, length: usize) -> usize {
    let bound = length as u64;
    let threshold = bound.wrapping_neg() % bound;
    loop {
        // Mix the LCG output before drawing: its low bits alone cycle through
        // exact permutations for power-of-two sample counts.
        let mut value = next(state);
        value = (value ^ (value >> 30)).wrapping_mul(0xbf58476d1ce4e5b9);
        value = (value ^ (value >> 27)).wrapping_mul(0x94d049bb133111eb);
        value ^= value >> 31;
        if value >= threshold {
            return (value % bound) as usize;
        }
    }
}
fn latency_metrics(baseline: &[f64], candidate: &[f64]) -> Option<Value> {
    if baseline.len() != candidate.len() {
        return None;
    }
    let b = median(baseline.to_vec())?;
    let c = median(candidate.to_vec())?;
    let ratios: Vec<_> = baseline.iter().zip(candidate).map(|(b, c)| b / c).collect();
    let ratio = median(ratios.clone())?;
    let median_sufficient = baseline.len() >= 11;
    let tail_sufficient = baseline.len() >= MINIMUM_TAIL_PAIRS;
    let mut state = SEED;
    let mut medians = Vec::new();
    let mut tails = Vec::new();
    if median_sufficient {
        for _ in 0..BOOTSTRAP_RESAMPLES {
            let mut paired = Vec::with_capacity(baseline.len());
            let mut rb = Vec::with_capacity(baseline.len());
            let mut rc = Vec::with_capacity(candidate.len());
            for _ in baseline {
                let index = bootstrap_index(&mut state, baseline.len());
                paired.push(ratios[index]);
                if tail_sufficient {
                    rb.push(baseline[index]);
                    rc.push(candidate[index]);
                }
            }
            medians.push(median(paired)?);
            if tail_sufficient {
                tails.push(percentile(&rb, 0.99)? / percentile(&rc, 0.99)?);
            }
        }
    }
    let interval = |values: &[f64]| Some([percentile(values, 0.025)?, percentile(values, 0.975)?]);
    let median_interval = interval(&medians);
    let tail_interval = interval(&tails);
    let decision = match median_interval {
        Some([low, _]) if low >= 1.5 => "useful_improvement",
        Some([_, high]) if high < 1. => "regression",
        _ => "inconclusive",
    };
    let bp = tail_sufficient
        .then(|| percentile(baseline, 0.99))
        .flatten();
    let cp = tail_sufficient
        .then(|| percentile(candidate, 0.99))
        .flatten();
    Some(json!({
        "baseline_median_ns": b,
        "candidate_median_ns": c,
        "paired_median_ratio": ratio,
        "median_ratio_ci95": median_interval,
        "median_sample_sufficient": median_sufficient,
        "median_decision": decision,
        "p99_sample_sufficient": tail_sufficient,
        "expected_samples_above_p99": baseline.len() as f64 * 0.01,
        "baseline_p99_ns": bp,
        "candidate_p99_ns": cp,
        "p99_ratio": bp.zip(cp).map(|(b, c)| b / c),
        "p99_ratio_ci95": tail_interval,
        "p99_threshold_met": tail_interval.map(|[low, _]| low >= 1.5),
    }))
}
fn schedule(observations: usize) -> Vec<bool> {
    let mut state = SEED;
    (0..observations)
        .map(|_| next(&mut state) >> 63 == 1)
        .collect()
}
fn hash_bytes(bytes: &[u8]) -> String {
    format!("{:x}", Sha256::digest(bytes))
}
fn hash_file(path: &Path) -> Result<String, String> {
    fs::read(path)
        .map(|b| hash_bytes(&b))
        .map_err(|e| format!("{}: {e}", path.display()))
}
fn filesystem_identity(path: &Path) -> Result<Value, String> {
    let canonical = fs::canonicalize(path).map_err(|e| format!("{}: {e}", path.display()))?;
    let metadata = fs::metadata(&canonical).map_err(|e| e.to_string())?;
    let option = if cfg!(target_os = "linux") {
        "-PT"
    } else {
        "-P"
    };
    let output = Command::new("df")
        .arg(option)
        .arg(&canonical)
        .env("LC_ALL", "C")
        .output()
        .map_err(|e| format!("Temporary filesystem identity: {e}"))?;
    if !output.status.success() {
        return Err(format!(
            "Temporary filesystem identity: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }
    let stdout = String::from_utf8(output.stdout).map_err(|e| e.to_string())?;
    let fields: Vec<_> = stdout
        .lines()
        .last()
        .unwrap_or("")
        .split_whitespace()
        .collect();
    let mount_field = if cfg!(target_os = "linux") { 6 } else { 5 };
    if fields.len() <= mount_field {
        return Err("Cannot identify temporary filesystem from POSIX df output".into());
    }
    Ok(json!({
        "identity": {"path": path, "canonical_path": canonical, "device": metadata.dev(), "inode": metadata.ino(), "filesystem_source": fields[0], "filesystem_type": if cfg!(target_os = "linux") { Some(fields[1]) } else { None }, "mountpoint": fields[mount_field..].join(" ")},
        "df": {"argv": ["df", option, canonical.to_str().ok_or("Non-UTF8 temporary directory")?], "stdout": stdout},
    }))
}
fn fixture_hash(name: &str, count: usize) -> String {
    let n = if name == "ids10000-unique-plus-duplicates" {
        10000
    } else {
        count
    };
    let mut ids: Vec<String> = (0..n)
        .rev()
        .map(|i| format!("fp-{i:05}@bench.invalid"))
        .collect();
    if name.contains("max255") {
        for id in &mut ids {
            id.push_str(&"x".repeat(255 - id.len()));
        }
    }
    if matches!(name, "xml10000" | "xml10000-fromXml") {
        let xml = format!(
            "<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\"><file><segments>{}</segments></file></nzb>",
            ids.iter()
                .map(|id| format!("<segment bytes=\"1\">{id}</segment>"))
                .collect::<String>()
        );
        return hash_bytes(xml.as_bytes());
    }
    if name == "ids10000-unique-plus-duplicates" {
        ids.extend(ids.clone().iter().map(|id| format!("\"<{id}>\"")));
    }
    let mut h = Sha256::new();
    for id in ids {
        h.update((id.len() as u32).to_be_bytes());
        h.update(id.as_bytes());
    }
    format!("{:x}", h.finalize())
}
fn execute(command: Command) -> Value {
    execute_with_timeout(command, Duration::from_secs(30))
}
fn execute_with_timeout(mut command: Command, timeout: Duration) -> Value {
    let start = Instant::now();
    let mut child = match command
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
    {
        Ok(c) => c,
        Err(e) => {
            return json!({"invalid_reason":"worker_start_failed","stderr":e.to_string(),"stdout":"","timed_out":false,"exit_code":null});
        }
    };
    let mut stdout = child.stdout.take().unwrap();
    let mut stderr = child.stderr.take().unwrap();
    let out = thread::spawn(move || {
        let mut b = Vec::new();
        let _ = stdout.read_to_end(&mut b);
        b
    });
    let err = thread::spawn(move || {
        let mut b = Vec::new();
        let _ = stderr.read_to_end(&mut b);
        b
    });
    let mut timed_out = false;
    let status = loop {
        match child.try_wait() {
            Ok(Some(s)) => break Ok(s),
            Ok(None) if start.elapsed() < timeout => thread::sleep(Duration::from_millis(10)),
            Ok(None) => {
                timed_out = true;
                let _ = child.kill();
                break child.wait();
            }
            Err(e) => {
                let _ = child.kill();
                let _ = child.wait();
                break Err(e);
            }
        }
    };
    let bytes = out.join().unwrap_or_default();
    let errors = err.join().unwrap_or_default();
    let mut row: Value = serde_json::from_slice(&bytes)
        .ok()
        .filter(Value::is_object)
        .unwrap_or_else(|| json!({"invalid_reason":"invalid_worker_output","stdout":String::from_utf8_lossy(&bytes)}));
    row["stdout"] = json!(String::from_utf8_lossy(&bytes));
    row["stderr"] = json!(String::from_utf8_lossy(&errors));
    row["timed_out"] = json!(timed_out);
    row["exit_code"] = json!(status.as_ref().ok().and_then(|s| s.code()));
    if timed_out {
        row["invalid_reason"] = json!("timeout");
    } else if !status.as_ref().map(|s| s.success()).unwrap_or(false) {
        row["invalid_reason"] = json!("worker_exit_failure");
    }
    row
}
fn valid(row: &Value, expected: &str, hash: &str) -> bool {
    row["correct"] == true
        && row.get("invalid_reason").is_none()
        && row["fingerprint"] == expected
        && row["cold_fingerprint"] == expected
        && row["fixture_sha256"] == hash
        && row["elapsed_ns"].as_u64().unwrap_or(0) > 0
        && row["cold_elapsed_ns"].as_u64().unwrap_or(0) > 0
}
fn command(o: &Options) -> Command {
    let mut c = Command::new(&o.php);
    c.current_dir(&o.project).args([
        "-d",
        if o.backend == "ffi" {
            "ffi.enable=true"
        } else {
            "ffi.enable=false"
        },
    ]);
    c
}
fn observation_command(o: &Options, candidate: bool) -> Command {
    let mut cmd = command(o);
    if candidate && o.backend == "ffi" {
        // Constructor failure must not silently select the subprocess backend.
        // The PHP baseline retains its original function configuration.
        cmd.args(["-d", "disable_functions=proc_open"]);
    }
    cmd
}
fn comparison_scope(coverage: &str) -> &'static str {
    if coverage == "full" {
        "Archived experimental Rust integration versus frozen native PHP; nine synthetic fixtures across cold and repeated public operations with uncommon p99 tails when at least 1000 eligible pairs are retained per fixture. Production remains PHP; no runtime adoption qualification."
    } else {
        "Archived experimental Rust integration versus frozen native PHP; five synthetic fixture median screen across cold and repeated public operations. Production remains PHP; no runtime adoption qualification."
    }
}
fn preflight_failure(o: &Options, sources: &Value, runtime: &Value, reason: &str) -> (Value, bool) {
    (
        json!({
            "benchmark":"nzb-article-fingerprint-paired-comparison",
            "candidate_status":CANDIDATE_STATUS,
            "comparison_scope":comparison_scope(&o.coverage),
            "adoption_qualified":false,
            "protocol":{"version":if o.coverage=="full" {4}else{3},"coverage":o.coverage,"backend":o.backend,"pairs_per_fixture":o.pairs,"tail_pairs_per_uncommon_fixture":o.tail_pairs},
            "sources":sources,
            "runtime":{"php":runtime},
            "fixtures":[],
            "observations":[],
            "summary":{},
            "invalid_observations":[{"invalid_reason":reason,"runtime":runtime}],
        }),
        true,
    )
}
struct Journal {
    path: PathBuf,
    writer: BufWriter<fs::File>,
}
impl Journal {
    fn create(output: &Path) -> Result<Self, String> {
        if output.file_name().is_none() {
            return Err("Full-coverage output must name a report file".into());
        }
        let path = output.with_extension("observations.jsonl");
        let file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&path)
            .map_err(|e| format!("Exclusive evidence journal {}: {e}", path.display()))?;
        Ok(Self {
            path,
            writer: BufWriter::new(file),
        })
    }
    fn record(&mut self, row: &Value) -> Result<(), String> {
        let bytes = serde_json::to_vec(row).map_err(|e| e.to_string())?;
        if let Err(error) = self
            .writer
            .write_all(&bytes)
            .and_then(|_| self.writer.write_all(b"\n"))
            .and_then(|_| self.writer.flush())
        {
            // Earlier records are already flushed. Retain the record that
            // could not be persisted in the separately retained console.
            println!(
                "{}",
                json!({"type":"journal_failure","path":self.path,"error":error.to_string(),"unpersisted_record":row})
            );
            let _ = std::io::stdout().flush();
            return Err(format!("Evidence journal {}: {error}", self.path.display()));
        }
        Ok(())
    }
}
fn journal_record(journal: &mut Option<Journal>, row: Value) -> Result<(), String> {
    if let Some(journal) = journal {
        journal.record(&row)?;
    }
    Ok(())
}
fn run(mut o: Options) -> Result<(Value, bool), String> {
    let mut journal = if o.coverage == "full" {
        o.output.as_deref().map(Journal::create).transpose()?
    } else {
        None
    };
    let mut fixture_definitions = FIXTURES.to_vec();
    if o.coverage == "full" {
        fixture_definitions.extend(FULL_FIXTURES);
    }
    let pair_counts: Vec<_> = fixture_definitions
        .iter()
        .map(|f| {
            if uncommon(f.0) {
                o.tail_pairs.unwrap_or(o.pairs)
            } else {
                o.pairs
            }
        })
        .collect();
    let orders = schedule(pair_counts.iter().map(|n| n + 1).sum());
    let fixtures:Vec<Value>=fixture_definitions.iter().zip(&pair_counts).map(|((name,count,operation,expected),pairs)|json!({"name":name,"input_count":count,"operation":operation,"uncommon":uncommon(name),"pairs":pairs,"expected_digest":expected,"fixture_sha256":fixture_hash(name,*count),"expected_digest_basis":"Independent Python hashlib/struct digest of normalized unique IDs"})).collect();
    journal_record(
        &mut journal,
        json!({"type":"setup","candidate_status":CANDIDATE_STATUS,"comparison_scope":comparison_scope(&o.coverage),"protocol":{"version":4,"coverage":o.coverage,"backend":o.backend,"pairs":o.pairs,"tail_pairs":o.tail_pairs,"seed":SEED,"orders_candidate_first":orders,"bootstrap_rng":BOOTSTRAP_RNG,"bootstrap_resamples":BOOTSTRAP_RESAMPLES,"bootstrap_seed":SEED,"timeout_seconds_per_worker":30,"warmup_pairs_per_fixture":1,"minimum_p99_pairs":MINIMUM_TAIL_PAIRS,"median_minimum_pairs":11,"useful_median_ratio_ci_lower_gate":1.5,"uncommon_p99_ratio_ci_lower_gate":1.5,"timing_scope":TIMING_SCOPE,"journal_scope":"Every setup and observation record flushed outside operation timing; no fsync durability claim"},"project":o.project,"baseline":o.baseline,"fixtures":fixtures}),
    )?;
    let mut paths = vec![
        ("baseline", o.baseline.clone()),
        (
            "candidate",
            o.project
                .join("tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php"),
        ),
        ("composer_lock", o.project.join("composer.lock")),
        (
            "vendor_installed",
            o.project.join("vendor/composer/installed.json"),
        ),
        (
            "xml_elements",
            o.project.join("app/Services/Nzb/NzbXmlElements.php"),
        ),
        (
            "rust_cargo_toml",
            o.project.join("docker/app/nzb-yenc-ffi/Cargo.toml"),
        ),
        (
            "coordinator_binary",
            env::current_exe().map_err(|e| e.to_string())?,
        ),
        (
            "benchmark",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/bin/benchmark-article-fingerprint.rs"),
        ),
        (
            "rust_cargo_lock",
            o.project.join("docker/app/nzb-yenc-ffi/Cargo.lock"),
        ),
        (
            "rust_accumulator",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/article_fingerprint.rs"),
        ),
        (
            "rust_abi",
            o.project.join("docker/app/nzb-yenc-ffi/src/lib.rs"),
        ),
        (
            "rust_worker",
            o.project
                .join("docker/app/nzb-yenc-ffi/src/bin/nntmux-article-fingerprint.rs"),
        ),
        (
            "php_worker",
            o.project
                .join("app/Services/Nzb/RustArticleFingerprintWorker.php"),
        ),
        (
            "native_library",
            o.project.join(format!(
                "docker/app/nzb-yenc-ffi/target/release/libnzbyenc.{}",
                if cfg!(target_os = "macos") {
                    "dylib"
                } else {
                    "so"
                }
            )),
        ),
        (
            "native_worker",
            o.project
                .join("docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint"),
        ),
    ];
    let mut sources = json!({});
    for (name, path) in &paths {
        let identity = match hash_file(path) {
            Ok(hash) => json!({"path":path,"sha256":hash}),
            Err(error) => {
                journal_record(
                    &mut journal,
                    json!({"type":"setup_failure","source":name,"path":path,"error":error}),
                )?;
                return Err(error);
            }
        };
        journal_record(
            &mut journal,
            json!({"type":"source_identity","source":name,"identity":identity}),
        )?;
        sources[*name] = identity;
    }
    let mut runtime_cmd = command(&o);
    runtime_cmd.args(["-r",r#"echo json_encode(['php_version'=>PHP_VERSION,'php_binary'=>realpath(PHP_BINARY),'php_binary_sha256'=>hash_file('sha256',PHP_BINARY),'php_sapi'=>PHP_SAPI,'php_uname'=>php_uname(),'ffi_enable'=>ini_get('ffi.enable'),'memory_limit'=>ini_get('memory_limit'),'opcache_enable_cli'=>ini_get('opcache.enable_cli'),'xdebug_modes'=>function_exists('xdebug_info')?xdebug_info('mode'):[],'extensions'=>get_loaded_extensions(),'simplexml'=>extension_loaded('SimpleXML'),'php_temp_directory'=>sys_get_temp_dir(),'sys_temp_dir'=>ini_get('sys_temp_dir'),'temporary_environment'=>['TMPDIR'=>getenv('TMPDIR'),'TMP'=>getenv('TMP'),'TEMP'=>getenv('TEMP')]],JSON_THROW_ON_ERROR);"#]);
    let runtime = execute(runtime_cmd);
    journal_record(
        &mut journal,
        json!({"type":"runtime_preflight","observation":runtime}),
    )?;
    if runtime.get("invalid_reason").is_some() {
        return Ok(preflight_failure(
            &o,
            &sources,
            &runtime,
            "runtime_metadata_failed",
        ));
    }
    let Some(binary_path) = runtime["php_binary"].as_str() else {
        return Ok(preflight_failure(
            &o,
            &sources,
            &runtime,
            "runtime_php_binary_missing",
        ));
    };
    let php_binary = PathBuf::from(binary_path);
    let php_hash = match hash_file(&php_binary) {
        Ok(hash) if php_binary.is_absolute() => hash,
        _ => {
            return Ok(preflight_failure(
                &o,
                &sources,
                &runtime,
                "runtime_php_binary_unreadable",
            ));
        }
    };
    if runtime["php_binary_sha256"] != php_hash {
        return Ok(preflight_failure(
            &o,
            &sources,
            &runtime,
            "runtime_php_binary_changed",
        ));
    }
    sources["php_binary"] = json!({"path":php_binary,"sha256":php_hash});
    journal_record(
        &mut journal,
        json!({"type":"source_identity","source":"php_binary","identity":sources["php_binary"]}),
    )?;
    paths.push(("php_binary", php_binary.clone()));
    o.php = php_binary
        .to_str()
        .ok_or("Non-UTF8 PHP binary path")?
        .into();
    let Some(temp_path) = runtime["php_temp_directory"].as_str() else {
        return Ok(preflight_failure(
            &o,
            &sources,
            &runtime,
            "runtime_php_temporary_directory_missing",
        ));
    };
    let php_temp = PathBuf::from(temp_path);
    let rust_temp = env::temp_dir();
    let mut temporary_filesystems = json!({});
    for (backend, path) in [("baseline_php", &php_temp), ("candidate_rust", &rust_temp)] {
        match filesystem_identity(path) {
            Ok(identity) => temporary_filesystems[backend] = identity,
            Err(error) => {
                return Ok(preflight_failure(
                    &o,
                    &sources,
                    &runtime,
                    &format!("runtime_{backend}_filesystem_unavailable: {error}"),
                ));
            }
        }
    }
    // Inspect live native state in an independent process so the timed process
    // keeps its cold PHP/FFI cache and full constructor/add/value/destructor cost.
    let driver = generated_driver();
    let mut proof_command = observation_command(&o, true);
    proof_command
        .args(["-r", &driver, "--"])
        .arg(&o.project)
        .arg(&o.baseline)
        .args([
            "candidate",
            "ids16",
            "16",
            "incremental",
            FIXTURES[0].3,
            "native-proof",
        ]);
    let native_proof = execute(proof_command);
    journal_record(
        &mut journal,
        json!({"type":"native_execution_proof","proof":native_proof}),
    )?;
    if native_proof.get("invalid_reason").is_some()
        || native_proof["native_execution_proven"] != true
        || native_proof["backend"] != format!("rust-{}", o.backend)
    {
        return Ok(preflight_failure(
            &o,
            &sources,
            &native_proof,
            "native_execution_proof_failed",
        ));
    }
    let compiler = Command::new("rustc")
        .arg("--version")
        .output()
        .ok()
        .map(|r| String::from_utf8_lossy(&r.stdout).trim().to_owned());
    journal_record(
        &mut journal,
        json!({"type":"preflight_complete","sources":sources,"runtime":{"php":runtime,"temporary_filesystems":temporary_filesystems,"rust_compiler_available_version":compiler,"native_execution_proof":native_proof}}),
    )?;
    let mut order_offset = 0;
    let mut scheduled = Vec::new();
    let mut observations = Vec::new();
    let mut invalids = Vec::new();
    let mut summary = json!({});
    if runtime.get("invalid_reason").is_some() {
        invalids.push(json!({"invalid_reason":"runtime_metadata_failed","runtime":runtime}));
    }
    for ((fixture, count, method, expected), pairs) in fixture_definitions.iter().zip(&pair_counts)
    {
        let hash = fixture_hash(fixture, *count);
        let mut paired = Vec::new();
        let mut hot = Vec::new();
        let mut cold = Vec::new();
        let mut baseline_hot = Vec::new();
        let mut candidate_hot = Vec::new();
        let mut baseline_cold = Vec::new();
        let mut candidate_cold = Vec::new();
        for pair in 0..=*pairs {
            let order = if orders[order_offset + pair] {
                ["candidate", "baseline"]
            } else {
                ["baseline", "candidate"]
            };
            let kind = if pair == 0 { "warmup" } else { "pair" };
            scheduled.push(json!({"fixture":fixture,"kind":kind,"pair":pair,"order":order}));
            let mut rows = json!({});
            for implementation in order {
                let mut c = observation_command(&o, implementation == "candidate");
                c.args(["-r", &driver, "--"])
                    .arg(&o.project)
                    .arg(&o.baseline)
                    .args([
                        implementation,
                        fixture,
                        &count.to_string(),
                        method,
                        expected,
                    ]);
                let mut row = execute(c);
                row["implementation"] = json!(implementation);
                row["fixture"] = json!(fixture);
                row["kind"] = json!(kind);
                row["pair"] = json!(pair);
                let expected_backend = if implementation == "baseline" {
                    "php".into()
                } else {
                    format!("rust-{}", o.backend)
                };
                let identity = &temporary_filesystems["baseline_php"]["identity"];
                let runtime_ok = row["php_binary"] == sources["php_binary"]["path"]
                    && row["php_binary_sha256"] == sources["php_binary"]["sha256"]
                    && row["php_temp_directory"]["path"] == identity["path"]
                    && row["php_temp_directory"]["canonical_path"] == identity["canonical_path"]
                    && row["php_temp_directory"]["device"] == identity["device"]
                    && row["php_temp_directory"]["inode"] == identity["inode"];
                row["runtime_identity_valid"] = json!(runtime_ok);
                row["requested_backend_valid"] = json!(row["backend"] == expected_backend);
                if (row["backend"] != expected_backend || !runtime_ok)
                    && row.get("invalid_reason").is_none()
                {
                    row["invalid_reason"] = json!("requested_backend_or_runtime_identity_mismatch");
                }
                if !valid(&row, expected, &hash) {
                    invalids.push(row.clone());
                }
                journal_record(
                    &mut journal,
                    json!({"type":"observation","observation":row}),
                )?;
                rows[implementation] = row.clone();
                observations.push(row);
            }
            if pair == 0 {
                continue;
            }
            let b = &rows["baseline"];
            let c = &rows["candidate"];
            let artifact_ok = c["artifact_path"]
                .as_str()
                .and_then(|p| hash_file(Path::new(p)).ok())
                .map(|h| {
                    c["artifact_sha256"] == h
                        && sources[if o.backend == "ffi" {
                            "native_library"
                        } else {
                            "native_worker"
                        }]["sha256"]
                            == h
                })
                .unwrap_or(false);
            let eligible = valid(b, expected, &hash)
                && valid(c, expected, &hash)
                && b["fixture_count"] == *count
                && c["fixture_count"] == *count
                && artifact_ok;
            let mut result = json!({"pair":pair,"order":order,"eligible":eligible,"baseline_ns":b["elapsed_ns"],"candidate_ns":c["elapsed_ns"],"cold_baseline_ns":b["cold_elapsed_ns"],"cold_candidate_ns":c["cold_elapsed_ns"]});
            if eligible {
                let h = b["elapsed_ns"].as_f64().unwrap() / c["elapsed_ns"].as_f64().unwrap();
                let k =
                    b["cold_elapsed_ns"].as_f64().unwrap() / c["cold_elapsed_ns"].as_f64().unwrap();
                hot.push(h);
                cold.push(k);
                baseline_hot.push(b["elapsed_ns"].as_f64().unwrap());
                candidate_hot.push(c["elapsed_ns"].as_f64().unwrap());
                baseline_cold.push(b["cold_elapsed_ns"].as_f64().unwrap());
                candidate_cold.push(c["cold_elapsed_ns"].as_f64().unwrap());
                result["baseline_over_candidate_ratio"] = json!(h);
                result["cold_baseline_over_candidate_ratio"] = json!(k);
            } else {
                invalids.push(json!({"fixture":fixture,"pair":pair,"invalid_reason":"pair_correctness_fixture_or_artifact_mismatch"}));
            }
            journal_record(
                &mut journal,
                json!({"type":"pair_result","fixture":fixture,"result":result}),
            )?;
            paired.push(result);
        }
        let h = median(hot.clone());
        let c = median(cold);
        let repeated_metrics = latency_metrics(&baseline_hot, &candidate_hot);
        let cold_metrics = latency_metrics(&baseline_cold, &candidate_cold);
        summary[*fixture] = json!({"pairs_total":pairs,"pairs_valid":hot.len(),"median_baseline_over_candidate_ratio":h,"median_speedup_percent":h.map(|v|100.*(v-1.)),"median_wall_time_reduction_percent":h.map(|v|100.*(1.-1./v)),"median_cold_baseline_over_candidate_ratio":c,"median_cold_speedup_percent":c.map(|v|100.*(v-1.)),"median_cold_wall_time_reduction_percent":c.map(|v|100.*(1.-1./v)),"pairs":paired,"uncommon":uncommon(fixture),"cold_metrics":cold_metrics,"repeated_metrics":repeated_metrics,"p99_sample_sufficient":hot.len()>=MINIMUM_TAIL_PAIRS});
        order_offset += pairs + 1;
    }
    let mut temporary_filesystems_at_end = json!({});
    for (backend, path) in [("baseline_php", &php_temp), ("candidate_rust", &rust_temp)] {
        temporary_filesystems_at_end[backend] = match filesystem_identity(path) {
            Ok(identity) => identity,
            Err(error) => json!({"error":error}),
        };
        if temporary_filesystems[backend]["identity"]
            != temporary_filesystems_at_end[backend]["identity"]
        {
            invalids.push(
                json!({"invalid_reason":"temporary_filesystem_identity_changed","backend":backend}),
            );
        }
    }
    for (name, path) in &paths {
        if hash_file(path).ok().as_deref() != sources[*name]["sha256"].as_str() {
            invalids.push(json!({"invalid_reason":"source_or_artifact_changed","source":name}));
        }
    }
    let invalid = !invalids.is_empty() || runtime.get("invalid_reason").is_some();
    for entry in summary.as_object_mut().unwrap().values_mut() {
        let eligible = !invalid && entry["pairs_valid"] == entry["pairs_total"];
        for lifecycle in ["cold_metrics", "repeated_metrics"] {
            if entry[lifecycle].is_object() {
                entry[lifecycle]["eligible"] = json!(eligible);
                if !eligible {
                    entry[lifecycle]["median_decision"] = json!("invalid");
                    entry[lifecycle]["p99_threshold_met"] = Value::Null;
                }
            }
        }
    }
    journal_record(
        &mut journal,
        json!({"type":"run_summary","summary":summary,"invalid_observations":invalids,"temporary_filesystems_at_end":temporary_filesystems_at_end,"adoption_qualified":false}),
    )?;
    Ok((
        json!({"benchmark":"nzb-article-fingerprint-paired-comparison","candidate_status":CANDIDATE_STATUS,"comparison_scope":comparison_scope(&o.coverage),"adoption_qualified":false,"protocol":{"version":if o.coverage=="full" {4}else{3},"coverage":o.coverage,"seed":SEED,"warmups_per_fixture":1,"warmups_per_observation":1,"pairs_per_fixture":o.pairs,"tail_pairs_per_uncommon_fixture":o.tail_pairs,"bootstrap_resamples":BOOTSTRAP_RESAMPLES,"bootstrap_seed":SEED,"bootstrap_rng":BOOTSTRAP_RNG,"median_minimum_pairs":11,"minimum_p99_pairs":MINIMUM_TAIL_PAIRS,"p99_estimator":"Separate baseline/candidate nearest rank ceil(0.99*N), then baseline/candidate ratio; never percentile of paired ratios","confidence_scope":"Pointwise paired bootstrap 95% intervals per fixture/lifecycle; not simultaneous or a production qualification","useful_median_ratio_ci_lower_gate":1.5,"uncommon_p99_ratio_ci_lower_gate":1.5,"timeout_seconds_per_worker":30,"backend":o.backend,"schedule":scheduled,"schedule_sha256":hash_bytes(serde_json::to_string(&scheduled).unwrap().as_bytes()),"timing_scope":TIMING_SCOPE,"limitations":["Synthetic workloads do not establish production indexing throughput or a production input distribution","Cold and repeated operation ratios must be read separately; a repeated-operation gain does not offset a cold regression","Uncommon tail estimates require at least 1000 independent eligible process pairs for that fixture; small-sample maxima are not p99 evidence","Warmup pairs warm OS caches; each observation uses a fresh PHP process. Complete PHP process latency is outside this caller-operation scope"],"correctness_gate":"Both literal independent digests, independent fixture hashes/counts, reviewed native-only caller source pins, separate-process live native state proof, FFI worker startup disabled or FFI disabled for worker, no unpinned backend alternative, unchanged sources/artifacts/runtime/temp filesystem identities"},"sources":sources,"runtime":{"php":runtime,"rust_compiler_available_version":compiler,"native_execution_proof":native_proof,"compiler_identity_note":"Available compiler version; build compiler identity not asserted","temporary_filesystems":temporary_filesystems,"temporary_filesystems_at_end":temporary_filesystems_at_end},"fixtures":fixtures,"observations":observations,"invalid_observations":invalids,"summary":summary,"completed_at_unix_seconds":SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs()}),
        invalid,
    ))
}
fn main() {
    let args: Vec<String> = env::args().skip(1).collect();
    if args.iter().any(|a| a == "--help" || a == "-h") {
        eprintln!(
            "Usage: benchmark-article-fingerprint --baseline=/absolute/file.php [--project=ROOT] [--backend=ffi|worker] [--php=PATH] [--coverage=screen|full] [--pairs=9] [--tail-pairs=1000] [--output=PATH]"
        );
        return;
    }
    match parse(args).and_then(|options| {
        let output = options.output.clone();
        run(options).map(|(report, invalid)| (report, invalid, output))
    }) {
        Ok((report, invalid, output)) => {
            let encoded = serde_json::to_string_pretty(&report).unwrap();
            // Keep completed observations in the retained console even if the
            // evidence destination becomes unwritable after the measurements.
            println!("{encoded}");
            if let Some(output) = output
                && let Err(e) = fs::write(output, &encoded)
            {
                eprintln!("{e}");
                std::process::exit(2);
            }
            if invalid {
                std::process::exit(1);
            }
        }
        Err(e) => {
            eprintln!("{e}");
            std::process::exit(2);
        }
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn native_proof_checks_live_state_and_rejects_backend_substitution() {
        let script = format!(
            "{}{}",
            NATIVE_ASSERTIONS,
            r#"
class ProofAccumulator {
    private static array $libraries=[];
    private $library=null; private $state=null; private $worker=null;
    public function __construct($library,$state,$worker,$path) { $this->library=$library; $this->state=$state; $this->worker=$worker; self::$libraries=[getmypid().':'.realpath($path)=>$library]; }
}
class ProofWorker { private $process; public function __construct($process) { $this->process=$process; } }
function rejects($operation) { try { $operation(); } catch (RuntimeException) { return; } throw new RuntimeException('Invalid backend accepted'); }
$path=PHP_BINARY; $ffi=FFI::cdef('int abs(int);'); $number=$ffi->new('int'); $state=FFI::addr($number);
$valid=new ProofAccumulator($ffi,$state,null,$path);
assertFingerprintBackend($valid,true,$path,$path);
rejects(fn()=>assertFingerprintBackend(new ProofAccumulator($ffi,null,null,$path),true,$path,$path));
rejects(fn()=>assertFingerprintBackend(new ProofAccumulator($ffi,$ffi->new('void *'),null,$path),true,$path,$path));
rejects(fn()=>assertFingerprintBackend(new ProofAccumulator(null,null,null,$path),false,$path,$path));
$process=proc_open([PHP_BINARY,'-r','sleep(5);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
try {
    $worker=new ProofWorker($process);
    assertFingerprintBackend(new ProofAccumulator(null,null,$worker,$path),false,$path,$path);
    rejects(fn()=>assertFingerprintBackend(new ProofAccumulator($ffi,$state,$worker,$path),true,$path,$path));
    rejects(fn()=>assertFingerprintBackend(new ProofAccumulator(null,null,$worker,$path),false,$path,'/wrong-worker'));
    proc_terminate($process,9); foreach($pipes as $pipe) fclose($pipe); proc_close($process);
    rejects(fn()=>assertFingerprintBackend(new ProofAccumulator(null,null,$worker,$path),false,$path,$path));
} finally { if(is_resource($process)) { proc_terminate($process,9); foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); } }
echo json_encode(['proof_assertions'=>true]);
"#
        );
        let mut command = Command::new("php");
        command.args(["-d", "ffi.enable=true", "-r", &script]);
        let result = execute(command);
        assert!(result.get("invalid_reason").is_none(), "{result}");
        assert_eq!(result["proof_assertions"], true);
    }

    #[test]
    fn native_source_guard_rejects_changed_or_substituted_class_files() {
        let script = format!(
            "{}{}",
            NATIVE_ASSERTIONS,
            r#"
function rejects($operation) { try { $operation(); } catch (RuntimeException) { return; } throw new RuntimeException('Unreviewed source accepted'); }
$file=tempnam(sys_get_temp_dir(),'nntmux-source-proof-');
$other=tempnam(sys_get_temp_dir(),'nntmux-source-proof-');
try {
    $source='<?php class ProofNativeSource {}';
    file_put_contents($file,$source); file_put_contents($other,$source); require $file;
    $hash=hash_file('sha256',$file);
    assertFingerprintSource(ProofNativeSource::class,$file,$hash);
    rejects(fn()=>assertFingerprintSource(ProofNativeSource::class,$file,str_repeat('0',64)));
    rejects(fn()=>assertFingerprintSource(ProofNativeSource::class,$other,$hash));
    file_put_contents($file,$source.' // altered');
    rejects(fn()=>assertFingerprintSource(ProofNativeSource::class,$file,$hash));
    echo json_encode(['source_guard'=>true]);
} finally { unlink($file); unlink($other); }
"#
        );
        let mut cmd = Command::new("php");
        cmd.args(["-r", &script]);
        let result = execute(cmd);
        assert!(result.get("invalid_reason").is_none(), "{result}");
        assert_eq!(result["source_guard"], true);
    }

    #[test]
    fn reviewed_native_only_source_hashes_match_current_callers() {
        let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../..");
        assert_eq!(
            hash_file(
                &root.join("tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php")
            )
            .unwrap(),
            NATIVE_CLASS_SHA256
        );
        assert_eq!(
            hash_file(&root.join("app/Services/Nzb/RustArticleFingerprintWorker.php")).unwrap(),
            NATIVE_WORKER_SHA256
        );
    }

    #[test]
    fn archived_candidate_loads_before_autoload_and_pins_its_own_source() {
        let fixture = "/tests/Fixtures/nzb-article-fingerprint/NzbArticleFingerprint.php";
        let driver = generated_driver();
        assert!(
            driver
                .find(&format!("else require $root.'{fixture}'"))
                .unwrap()
                < driver.find("require $root.'/vendor/autoload.php'").unwrap()
        );
        assert!(driver.contains(&format!("[$class,$root.'{fixture}',NATIVE_CLASS_SHA256]")));
        assert!(!driver.contains("/app/Services/Nzb/NzbArticleFingerprint.php"));
    }
    #[test]
    fn archived_candidate_status_and_scope_survive_preflight_failure_for_both_coverages() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        for coverage in ["screen", "full"] {
            let options = parse(vec![
                format!("--baseline={}", baseline.display()),
                format!("--coverage={coverage}"),
            ])
            .unwrap();
            let (report, invalid) =
                preflight_failure(&options, &json!({}), &json!({}), "test_failure");
            assert!(invalid);
            assert_eq!(
                report["candidate_status"],
                "experimental_withdrawn_from_production"
            );
            assert_eq!(report["comparison_scope"], comparison_scope(coverage));
            assert!(comparison_scope(coverage).contains("Production remains PHP"));
            if coverage == "full" {
                assert_eq!(report["protocol"]["version"], 4);
                assert!(comparison_scope(coverage).contains("nine synthetic fixtures"));
                assert!(comparison_scope(coverage).contains("p99 tails"));
            } else {
                assert_eq!(report["protocol"]["version"], 3);
                assert!(
                    comparison_scope(coverage).contains("five synthetic fixture median screen")
                );
            }
        }
    }
    #[test]
    fn ffi_candidate_disables_worker_startup_without_changing_baseline() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        let options = parse(vec![format!("--baseline={}", baseline.display())]).unwrap();
        for (candidate, expected) in [(false, true), (true, false)] {
            let mut cmd = observation_command(&options, candidate);
            cmd.args([
                "-r",
                "echo json_encode(['proc_open_available'=>function_exists('proc_open')]);",
            ]);
            assert_eq!(execute(cmd)["proc_open_available"], expected);
        }
    }

    #[test]
    fn median_handles_empty_even_and_odd() {
        assert_eq!(median(vec![]), None);
        assert_eq!(median(vec![9., 1., 2.]), Some(2.));
        assert_eq!(median(vec![9., 1.]), Some(5.));
    }
    #[test]
    fn median_rejects_invalid_latency_samples() {
        for values in [vec![0.], vec![-1.], vec![f64::NAN], vec![f64::INFINITY]] {
            assert_eq!(median(values), None);
        }
    }
    #[test]
    fn full_fixture_hashes_match_independent_python() {
        for (name, count, expected) in [
            (
                "xml10000-fromXml",
                10000,
                "af80e94e9b7cfe72a1c857fa5ec4128f812e0c3996df9ecafd34bc971ec63bda",
            ),
            (
                "ids16-max255",
                16,
                "f75fce98db84a0fcc9abe2dce8e5f27d82052b8f22e87aed12ba5441cb19c51f",
            ),
            (
                "incremental25000-max255-spill",
                25000,
                "61e93425e7afee783523b3924c02e7e1dfca3dc9fac70ebe51aa8b1604061fa3",
            ),
        ] {
            assert_eq!(fixture_hash(name, count), expected);
        }
    }
    #[test]
    fn seeded_order_is_repeatable_and_mixed() {
        let orders = schedule(80);
        assert_eq!(orders, schedule(80));
        assert_eq!(orders.len(), 80);
        assert!(orders.contains(&true) && orders.contains(&false));
    }
    #[test]
    fn fixture_hashes_match_independent_python() {
        let expected = [
            "fe3f4012896e88d5cfb545093c2e5683941588431f0c7afd121928aadb55eab0",
            "c0baa01a5d0c17303c415495593c759b222b32c50c967f462bfb26b8b6df3ac9",
            "6a99f06802f2594babf587d9ee9b5498a2167eb076036a6fbde70bff01d65475",
            "4e66499962861310053dc4d953a783e1af46e84bd09f1d45324cf78981b2d2d2",
            "af80e94e9b7cfe72a1c857fa5ec4128f812e0c3996df9ecafd34bc971ec63bda",
        ];
        for (fixture, hash) in FIXTURES.iter().zip(expected) {
            assert_eq!(fixture_hash(fixture.0, fixture.1), hash);
        }
        assert_eq!(
            hash_bytes(b"abc"),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
    }
    #[test]
    fn invalid_arguments_are_rejected() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        let required = format!("--baseline={}", baseline.display());
        assert!(
            parse(vec![
                required.clone(),
                "--pairs=15".into(),
                "--backend=worker".into()
            ])
            .is_ok()
        );
        for arg in ["--pairs=0", "--pairs=101", "--backend=other", "--unknown=x"] {
            assert!(parse(vec![required.clone(), arg.into()]).is_err());
        }
    }
    #[test]
    fn full_coverage_accepts_independent_tail_sample_counts() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        assert!(
            parse(vec![
                format!("--baseline={}", baseline.display()),
                "--coverage=full".into(),
                "--pairs=1000".into(),
            ])
            .is_ok()
        );
    }
    #[test]
    fn tail_count_requires_opt_in_full_coverage() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        let required = format!("--baseline={}", baseline.display());
        assert!(
            parse(vec![
                required.clone(),
                "--coverage=full".into(),
                "--pairs=31".into(),
                "--tail-pairs=1000".into(),
            ])
            .is_ok()
        );
        assert!(parse(vec![required, "--tail-pairs=1000".into()]).is_err());
    }
    #[test]
    fn small_screens_never_qualify_a_tail_or_hide_cold_regression() {
        let baseline = vec![10.; 31];
        let candidate = vec![20.; 31];
        let metrics = latency_metrics(&baseline, &candidate).unwrap();
        assert_eq!(metrics["paired_median_ratio"], 0.5);
        assert_eq!(metrics["median_ratio_ci95"], json!([0.5, 0.5]));
        assert_eq!(metrics["median_decision"], "regression");
        assert_eq!(metrics["p99_sample_sufficient"], false);
        for field in [
            "baseline_p99_ns",
            "candidate_p99_ns",
            "p99_ratio",
            "p99_ratio_ci95",
            "p99_threshold_met",
        ] {
            assert!(metrics[field].is_null(), "{field}");
        }
    }
    #[test]
    fn noisy_power_of_two_samples_have_noncollapsed_bootstrap_intervals() {
        let baseline: Vec<_> = (1..=16).map(f64::from).collect();
        let candidate = vec![1.; 16];
        let metrics = latency_metrics(&baseline, &candidate).unwrap();
        let interval = metrics["median_ratio_ci95"].as_array().unwrap();
        assert!(interval[0].as_f64().unwrap() < interval[1].as_f64().unwrap());
        assert_eq!(metrics, latency_metrics(&baseline, &candidate).unwrap());
    }
    #[test]
    fn empirical_bootstrap_resamples_repeat_and_omit_indices() {
        for length in [16, 32, 64, 1024] {
            let draw = || {
                let mut state = SEED;
                (0..length)
                    .map(|_| bootstrap_index(&mut state, length))
                    .collect::<Vec<_>>()
            };
            let sample = draw();
            assert_eq!(sample, draw());
            assert!(sample.iter().all(|index| *index < length));
            let unique: std::collections::HashSet<_> = sample.into_iter().collect();
            assert!(
                unique.len() < length,
                "resample unexpectedly permuted all {length} indices"
            );
        }
    }
    #[test]
    fn p99_compares_marginal_latencies_instead_of_paired_ratio_percentiles() {
        let mut baseline = vec![100.; 1000];
        let mut candidate = vec![10.; 1000];
        for value in baseline.iter_mut().take(20) {
            *value = 1000.;
        }
        for value in candidate.iter_mut().skip(20).take(20) {
            *value = 500.;
        }
        let metrics = latency_metrics(&baseline, &candidate).unwrap();
        assert_eq!(metrics["baseline_p99_ns"], 1000.);
        assert_eq!(metrics["candidate_p99_ns"], 500.);
        assert_eq!(metrics["p99_ratio"], 2.);
        assert_ne!(
            percentile(
                &baseline
                    .iter()
                    .zip(&candidate)
                    .map(|(b, c)| b / c)
                    .collect::<Vec<_>>(),
                0.99
            ),
            Some(2.)
        );
        assert_eq!(metrics["p99_sample_sufficient"], true);
        assert!(metrics["p99_ratio_ci95"].is_array());
    }
    #[test]
    fn metrics_reject_missing_mismatched_and_invalid_samples() {
        assert!(latency_metrics(&[], &[]).is_none());
        assert!(latency_metrics(&[1.], &[1., 2.]).is_none());
        assert!(latency_metrics(&[1.], &[0.]).is_none());
        assert!(latency_metrics(&[f64::INFINITY], &[1.]).is_none());
        let small = latency_metrics(&[3.], &[1.]).unwrap();
        assert!(small["median_ratio_ci95"].is_null());
        assert_eq!(small["median_decision"], "inconclusive");
    }
    #[test]
    fn failed_runtime_preflights_retain_raw_outputs_and_failure_causes() {
        let baseline = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("Cargo.toml");
        let options = parse(vec![format!("--baseline={}", baseline.display())]).unwrap();
        let missing = execute(Command::new("/nntmux-fingerprint-test/no-such-php"));
        assert_eq!(missing["invalid_reason"], "worker_start_failed");
        let mut crash = Command::new("sh");
        crash.args(["-c", "printf 'runtime crash' >&2; exit 7"]);
        let crash = execute(crash);
        assert_eq!(crash["invalid_reason"], "worker_exit_failure");
        assert_eq!(crash["stderr"], "runtime crash");
        assert_eq!(crash["exit_code"], 7);
        let mut malformed = Command::new("sh");
        malformed.args(["-c", "printf 'malformed runtime JSON'"]);
        let malformed = execute(malformed);
        assert_eq!(malformed["invalid_reason"], "invalid_worker_output");
        assert_eq!(malformed["stdout"], "malformed runtime JSON");
        let mut timeout = Command::new("sh");
        timeout.args(["-c", "exec sleep 1"]);
        let timeout = execute_with_timeout(timeout, Duration::from_millis(10));
        assert_eq!(timeout["invalid_reason"], "timeout");
        assert_eq!(timeout["timed_out"], true);
        for runtime in [missing, crash, malformed, timeout] {
            let (report, invalid) =
                preflight_failure(&options, &json!({}), &runtime, "runtime_metadata_failed");
            assert!(invalid);
            assert_eq!(report["runtime"]["php"], runtime);
            assert_eq!(report["invalid_observations"][0]["runtime"], runtime);
            assert_eq!(report["summary"], json!({}));
            assert_eq!(report["observations"], json!([]));
            assert_eq!(report["adoption_qualified"], false);
        }
    }
    #[test]
    fn journals_are_exclusive_and_each_record_is_visible_before_drop() {
        struct OwnedDirectory(PathBuf);
        impl Drop for OwnedDirectory {
            fn drop(&mut self) {
                let _ = fs::remove_dir_all(&self.0);
            }
        }
        let path = env::temp_dir().join(format!(
            "nntmux-fingerprint-journal-test-{}-{}",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir(&path).unwrap();
        let owned = OwnedDirectory(path);
        let report = owned.0.join("report.json");
        let mut journal = Journal::create(&report).unwrap();
        let setup = json!({"type":"setup","fixtures":["ids16"]});
        let observation = json!({"type":"observation","invalid_reason":"timeout"});
        journal.record(&setup).unwrap();
        let first = fs::read_to_string(&journal.path).unwrap();
        assert_eq!(first.lines().count(), 1);
        assert_eq!(serde_json::from_str::<Value>(first.trim()).unwrap(), setup);
        journal.record(&observation).unwrap();
        let before = fs::read(&journal.path).unwrap();
        let rows: Vec<Value> = std::str::from_utf8(&before)
            .unwrap()
            .lines()
            .map(|line| serde_json::from_str(line).unwrap())
            .collect();
        assert_eq!(rows, vec![setup, observation]);
        assert!(Journal::create(&report).is_err());
        assert!(Journal::create(&owned.0.join("missing/report.json")).is_err());
        // A write failure cannot alter the earlier flushed records.
        let mut read_only = Journal {
            path: journal.path.clone(),
            writer: BufWriter::new(fs::File::open(&journal.path).unwrap()),
        };
        assert!(read_only.record(&json!({"type":"unpersisted"})).is_err());
        assert_eq!(fs::read(&journal.path).unwrap(), before);
        assert!(!report.exists());
    }
}
