//! Historical paired-performance fixtures, statistics and receipt validation.
//!
//! Live benchmark execution is unavailable until process custody and surviving
//! cancellation containment have a separately reviewed implementation.
use crate::tooling::{file_sha256, summarize};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;
use std::ffi::c_int;
use std::fs::{self, OpenOptions};
use std::io::Write;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::Duration;

type Options = BTreeMap<String, String>;
const SEED: u32 = 582139;
const REPETITIONS: usize = 5;
const OBSERVATIONS: usize = 11;
const MIN_BATCH_MS: f64 = 250.;
const CHUNK_MEMORY_LIMIT: usize = 32 * 1024 * 1024;
const MAX_CHUNK_SLOTS: usize = 8;
const SCALAR_CHUNK_SLOTS: usize = 4096;
const METADATA_SLOT_LIMIT: usize = 1024 * 1024;

pub(crate) const LIVE_EXECUTION_UNAVAILABLE: &str = "live benchmark execution unavailable: owned process custody and surviving cancellation containment are not qualified";

/// These refusals are unconditional, including in tests and on every platform.
/// Arguments and paths are deliberately unused; no receipt implies a live run.
pub fn run(_options: &Options) -> Result<Value, String> {
    Err(LIVE_EXECUTION_UNAVAILABLE.into())
}

pub fn worker(_options: &Options) -> Result<Value, String> {
    Err(LIVE_EXECUTION_UNAVAILABLE.into())
}

pub(crate) fn capture_child(_options: &Options) -> Result<Value, String> {
    Err(LIVE_EXECUTION_UNAVAILABLE.into())
}

fn capture_fresh(
    _command: &mut Command,
    _executable: &Path,
    _directory: &Path,
    _timeout: Duration,
) -> Result<ChildReceipt, String> {
    Err(LIVE_EXECUTION_UNAVAILABLE.into())
}

fn capture(
    _command: &mut Command,
    _directory: &Path,
    _timeout: Duration,
) -> Result<ChildReceipt, String> {
    Err(LIVE_EXECUTION_UNAVAILABLE.into())
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

struct ChildReceipt {
    report: Value,
    lifetime_ms: f64,
    rss_bytes: u64,
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

// Historical fixture construction for the separate offline evidence verifier.
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

// Historical receipt data only: native loading and invocation are removed.
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
fn summarize_variant(
    children: &[ChildReceipt],
    work: &Workload,
    artifact: &str,
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
                artifact,
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
// Pure historical witness comparison, retained without any native invocation.
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
    fn live_interfaces_refuse_before_read_create_spawn_or_load() {
        let root = temporary("execution-denied");
        let output = root.join("must-not-create");
        let absent = root.join("must-not-read-or-load");
        let existing = root.join("existing-receipt.json");
        fs::write(&existing, b"historical bytes must remain unchanged").unwrap();
        for path in [&absent, &existing] {
            let options = Options::from([
                ("--output-dir".into(), output.to_string_lossy().into_owned()),
                ("--directory".into(), output.to_string_lossy().into_owned()),
                ("--request".into(), path.to_string_lossy().into_owned()),
                ("--library".into(), path.to_string_lossy().into_owned()),
                ("--candidate".into(), path.to_string_lossy().into_owned()),
            ]);
            for result in [run(&options), worker(&options), capture_child(&options)] {
                assert_eq!(result.unwrap_err(), LIVE_EXECUTION_UNAVAILABLE);
            }
            let mut command = Command::new(path);
            assert_eq!(
                capture(&mut command, &output, Duration::ZERO)
                    .err()
                    .unwrap(),
                LIVE_EXECUTION_UNAVAILABLE
            );
            assert_eq!(
                capture_fresh(&mut command, path, &output, Duration::ZERO)
                    .err()
                    .unwrap(),
                LIVE_EXECUTION_UNAVAILABLE
            );
        }
        assert!(!output.exists());
        assert!(!absent.exists());
        assert_eq!(
            fs::read(&existing).unwrap(),
            b"historical bytes must remain unchanged"
        );
        assert_eq!(fs::read_dir(&root).unwrap().count(), 1);
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn missing_or_malformed_options_cannot_change_execution_denial() {
        for options in [
            Options::new(),
            Options::from([("--output-dir".into(), "relative".into())]),
            Options::from([("--unknown".into(), "invalid".into())]),
        ] {
            for result in [run(&options), worker(&options), capture_child(&options)] {
                assert_eq!(result.unwrap_err(), LIVE_EXECUTION_UNAVAILABLE);
            }
        }
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
        let artifact = "artifact";
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
        let result = summarize_variant(&children, &work, artifact).unwrap();
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
}
