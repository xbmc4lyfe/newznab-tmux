use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    env, fs,
    io::Read,
    path::{Path, PathBuf},
    process::{Command, Stdio},
    thread,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};
const SEED: u64 = 20261014;
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
// Only fixture construction and calls to the public PHP API live in this driver.
const DRIVER: &str = r#"
[$root,$baseline,$implementation,$fixture,$count,$method,$expected]=array_slice($argv,1);
$ids=[]; for($i=($fixture==='ids10000-unique-plus-duplicates'?10000:(int)$count)-1;$i>=0;$i--) $ids[]=sprintf('fp-%05d@bench.invalid',$i);
if($fixture==='ids10000-unique-plus-duplicates') $ids=[...$ids,...array_map(static fn($id)=>'"<'.$id.'>"',$ids)];
$payload=$ids; $hash=hash_init('sha256');
if($method==='fromContents') { $payload='<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file><segments>'; foreach($ids as $id) $payload.='<segment bytes="1">'.$id.'</segment>'; $payload.='</segments></file></nzb>'; hash_update($hash,$payload); }
else foreach($ids as $id) hash_update($hash,pack('N',strlen($id)).$id);
$class='App\\Services\\Nzb\\NzbArticleFingerprint';
if($implementation==='baseline') require $baseline;
require $root.'/vendor/autoload.php'; if(!class_exists($class)) throw new RuntimeException('Fingerprint class missing');
$library=$root.'/docker/app/nzb-yenc-ffi/target/release/libnzbyenc.'.(PHP_OS_FAMILY==='Darwin'?'dylib':'so');
$worker=$root.'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint';
Illuminate\Container\Container::getInstance()->instance('config',new Illuminate\Config\Repository(['yenc'=>['native_library'=>$library]]));
$ffi=extension_loaded('FFI')&&!in_array(strtolower((string)ini_get('ffi.enable')),['','0','false','off'],true);
$artifact=$ffi?$library:$worker; $artifactHash=is_file($artifact)?hash_file('sha256',$artifact):null;
if($implementation==='candidate'&&!is_string($artifactHash)) throw new RuntimeException('Runtime artifact missing');
$start=hrtime(true); $cold=$class::$method($payload); $coldTime=hrtime(true)-$start;
$start=hrtime(true); $hot=$class::$method($payload); $hotTime=hrtime(true)-$start;
if($implementation==='candidate') { $libraries=(new ReflectionProperty($class,'libraries'))->getValue(); $used=isset($libraries[getmypid().':'.realpath($library)]); if($used!==$ffi||hash_file('sha256',$artifact)!==$artifactHash) throw new RuntimeException('Pinned backend or artifact changed'); }
echo json_encode(['fixture_count'=>count($ids),'fixture_sha256'=>hash_final($hash),'cold_elapsed_ns'=>$coldTime,'elapsed_ns'=>$hotTime,'cold_fingerprint'=>$cold,'fingerprint'=>$hot,'correct'=>$cold===$expected&&$hot===$expected,'backend'=>$implementation==='baseline'?'php':($ffi?'rust-ffi':'rust-worker'),'artifact_path'=>$implementation==='candidate'?realpath($artifact):null,'artifact_sha256'=>$implementation==='candidate'?$artifactHash:null],JSON_THROW_ON_ERROR);
"#;
#[derive(Debug)]
struct Options {
    baseline: PathBuf,
    project: PathBuf,
    backend: String,
    php: String,
    pairs: usize,
    output: Option<PathBuf>,
}
fn parse(args: Vec<String>) -> Result<Options, String> {
    let mut o = Options {
        baseline: PathBuf::new(),
        project: env::current_dir().map_err(|e| e.to_string())?,
        backend: "ffi".into(),
        php: "php".into(),
        pairs: 9,
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
            "pairs" => {
                o.pairs = value.parse().map_err(|_| "Invalid pairs")?;
                if !(1..=100).contains(&o.pairs) {
                    return Err("Pairs must be 1..100".into());
                }
            }
            _ => return Err(format!("Unknown or invalid option: {key}")),
        }
    }
    if !o.baseline.is_absolute() || !o.baseline.is_file() {
        return Err("--baseline must be an absolute readable PHP file".into());
    }
    o.project = fs::canonicalize(&o.project).map_err(|e| e.to_string())?;
    Ok(o)
}
fn median(mut values: Vec<f64>) -> Option<f64> {
    if values.is_empty() {
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
fn schedule(pairs: usize) -> Vec<bool> {
    let mut state = SEED;
    (0..5 * (pairs + 1))
        .map(|_| {
            state = state
                .wrapping_mul(6364136223846793005)
                .wrapping_add(1442695040888963407);
            state >> 63 == 1
        })
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
    if name == "xml10000" {
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
fn execute(mut command: Command) -> Value {
    let start = Instant::now();
    let mut child = match command
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
    {
        Ok(c) => c,
        Err(e) => return json!({"invalid_reason":"worker_start_failed","stderr":e.to_string()}),
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
            Ok(None) if start.elapsed() < Duration::from_secs(30) => {
                thread::sleep(Duration::from_millis(10))
            }
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
fn run(o: Options) -> Result<(Value, bool), String> {
    let paths = [
        ("baseline", o.baseline.clone()),
        (
            "candidate",
            o.project.join("app/Services/Nzb/NzbArticleFingerprint.php"),
        ),
        ("composer_lock", o.project.join("composer.lock")),
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
        sources[*name] = json!({"path":path,"sha256":hash_file(path)?});
    }
    let mut runtime_cmd = command(&o);
    runtime_cmd.args(["-r",r#"echo json_encode(['php_version'=>PHP_VERSION,'php_binary'=>PHP_BINARY,'php_sapi'=>PHP_SAPI,'php_uname'=>php_uname(),'ffi_enable'=>ini_get('ffi.enable'),'memory_limit'=>ini_get('memory_limit'),'opcache_enable_cli'=>ini_get('opcache.enable_cli'),'xdebug_modes'=>function_exists('xdebug_info')?xdebug_info('mode'):[],'extensions'=>get_loaded_extensions(),'simplexml'=>extension_loaded('SimpleXML')],JSON_THROW_ON_ERROR);"#]);
    let runtime = execute(runtime_cmd);
    let compiler = Command::new("rustc")
        .arg("--version")
        .output()
        .ok()
        .map(|r| String::from_utf8_lossy(&r.stdout).trim().to_owned());
    let orders = schedule(o.pairs);
    let mut scheduled = Vec::new();
    let mut observations = Vec::new();
    let mut invalids = Vec::new();
    let mut summary = json!({});
    if runtime.get("invalid_reason").is_some() {
        invalids.push(json!({"invalid_reason":"runtime_metadata_failed","runtime":runtime}));
    }
    for (index, (fixture, count, method, expected)) in FIXTURES.iter().enumerate() {
        let hash = fixture_hash(fixture, *count);
        let mut paired = Vec::new();
        let mut hot = Vec::new();
        let mut cold = Vec::new();
        for pair in 0..=o.pairs {
            let order = if orders[index * (o.pairs + 1) + pair] {
                ["candidate", "baseline"]
            } else {
                ["baseline", "candidate"]
            };
            let kind = if pair == 0 { "warmup" } else { "pair" };
            scheduled.push(json!({"fixture":fixture,"kind":kind,"pair":pair,"order":order}));
            let mut rows = json!({});
            for implementation in order {
                let mut c = command(&o);
                c.args(["-r", DRIVER, "--"])
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
                if !valid(&row, expected, &hash) {
                    invalids.push(row.clone());
                }
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
                .map(|h| c["artifact_sha256"] == h)
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
                result["baseline_over_candidate_ratio"] = json!(h);
                result["cold_baseline_over_candidate_ratio"] = json!(k);
            } else {
                invalids.push(json!({"fixture":fixture,"pair":pair,"invalid_reason":"pair_correctness_fixture_or_artifact_mismatch"}));
            }
            paired.push(result);
        }
        let h = median(hot.clone());
        let c = median(cold);
        summary[*fixture] = json!({"pairs_total":o.pairs,"pairs_valid":hot.len(),"median_baseline_over_candidate_ratio":h,"median_speedup_percent":h.map(|v|100.*(v-1.)),"median_wall_time_reduction_percent":h.map(|v|100.*(1.-1./v)),"median_cold_baseline_over_candidate_ratio":c,"median_cold_speedup_percent":c.map(|v|100.*(v-1.)),"median_cold_wall_time_reduction_percent":c.map(|v|100.*(1.-1./v)),"pairs":paired});
    }
    for (name, path) in &paths {
        if hash_file(path).ok().as_deref() != sources[*name]["sha256"].as_str() {
            invalids.push(json!({"invalid_reason":"source_or_artifact_changed","source":name}));
        }
    }
    let invalid = !invalids.is_empty() || runtime.get("invalid_reason").is_some();
    let fixtures:Vec<Value>=FIXTURES.iter().map(|(name,count,operation,expected)|json!({"name":name,"input_count":count,"operation":operation,"expected_digest":expected,"fixture_sha256":fixture_hash(name,*count),"expected_digest_basis":"Independent Python hashlib/struct digest of normalized unique IDs"})).collect();
    Ok((
        json!({"benchmark":"nzb-article-fingerprint-paired-comparison","protocol":{"version":3,"seed":SEED,"warmups_per_fixture":1,"warmups_per_observation":1,"pairs_per_fixture":o.pairs,"timeout_seconds_per_worker":30,"backend":o.backend,"schedule":scheduled,"schedule_sha256":hash_bytes(serde_json::to_string(&scheduled).unwrap().as_bytes()),"timing_scope":"Fresh PHP process: cold complete public operation then one repeated complete operation; fixture generation, autoload, artifact hashing and process startup excluded. Accumulator, XML parsing and Rust worker startup included.","correctness_gate":"Both literal independent digests, independent fixture hashes/counts, pinned backend and unchanged sources/artifacts"},"sources":sources,"runtime":{"php":runtime,"rust_compiler_available_version":compiler,"compiler_identity_note":"Available compiler version; build compiler identity not asserted"},"fixtures":fixtures,"observations":observations,"invalid_observations":invalids,"summary":summary,"completed_at_unix_seconds":SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs()}),
        invalid,
    ))
}
fn main() {
    let args: Vec<String> = env::args().skip(1).collect();
    if args.iter().any(|a| a == "--help" || a == "-h") {
        eprintln!(
            "Usage: benchmark-article-fingerprint --baseline=/absolute/file.php [--project=ROOT] [--backend=ffi|worker] [--php=PATH] [--pairs=9] [--output=PATH]"
        );
        return;
    }
    match parse(args).and_then(|options| {
        let output = options.output.clone();
        run(options).map(|(report, invalid)| (report, invalid, output))
    }) {
        Ok((report, invalid, output)) => {
            let encoded = serde_json::to_string_pretty(&report).unwrap();
            if let Some(output) = output
                && let Err(e) = fs::write(output, &encoded)
            {
                eprintln!("{e}");
                std::process::exit(2);
            }
            println!("{encoded}");
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
    fn median_handles_empty_even_and_odd() {
        assert_eq!(median(vec![]), None);
        assert_eq!(median(vec![9., 1., 2.]), Some(2.));
        assert_eq!(median(vec![9., 1.]), Some(5.));
    }
    #[test]
    fn seeded_order_is_repeatable_and_mixed() {
        let orders = schedule(15);
        assert_eq!(orders, schedule(15));
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
}
