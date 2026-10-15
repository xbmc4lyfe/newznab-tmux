//! Controller-owned, bounded guest-visible identity. This is not host attestation.
use serde_json::{Value, json};
use std::{collections::BTreeMap, path::Path};

#[path = "control_resources.rs"]
mod resources;

const RAW_BUDGET: usize = 1_048_576;
const MAX_CPUS: usize = 4096;
pub(super) const ENDPOINT_CAP: usize = 8_388_608;

struct Reader {
    remaining: usize,
    sources: Vec<Value>,
}

impl Reader {
    fn read(&mut self, path: &Path, cap: usize) -> Value {
        let cap = cap.min(self.remaining.saturating_sub(1));
        let receipt = if cap == 0 {
            json!({"path":path,"status":"unavailable","error_kind":"budget_exceeded","error":"shared raw-input budget exhausted"})
        } else {
            // Charge the bounded lookahead too. Failed/oversized reads are not
            // free attempts against the shared input-work budget.
            self.remaining -= cap + 1;
            match super::read_host_source_typed(path, cap) {
                Ok(bytes) => {
                    self.remaining += cap + 1 - bytes.len();
                    match std::str::from_utf8(&bytes) {
                        Ok(raw) => json!({"path":path,"status":"captured","bytes":bytes.len(),
                            "sha256":super::digest(&bytes),"raw":raw}),
                        Err(_) => json!({"path":path,"status":"unavailable","bytes":bytes.len(),
                            "sha256":super::digest(&bytes),"error_kind":"invalid_data","error":"source is not UTF-8"}),
                    }
                }
                Err(error) => json!({"path":path,"status":"unavailable","error":error.to_string(),
                "error_kind":match error.kind() {
                    std::io::ErrorKind::NotFound => "not_found",
                    std::io::ErrorKind::PermissionDenied => "permission_denied",
                    std::io::ErrorKind::InvalidData => "invalid_data_or_budget_exceeded",
                    _ => "other",
                }}),
            }
        };
        self.sources.push(receipt.clone());
        receipt
    }
}

fn text(receipt: &Value) -> Option<&str> {
    (receipt["status"] == "captured")
        .then(|| receipt["raw"].as_str())
        .flatten()
}

pub(super) fn cpu_list(raw: &str) -> Option<Vec<u32>> {
    let mut ids = std::collections::BTreeSet::new();
    if raw.is_empty() || raw.len() > 32_768 {
        return None;
    }
    for range in raw.split(',') {
        let number = |s: &str| {
            (!s.is_empty() && s.bytes().all(|b| b.is_ascii_digit()))
                .then(|| s.parse::<u32>().ok())
                .flatten()
        };
        let (first, last) = match range.split_once('-') {
            Some((a, b)) => (number(a)?, number(b)?),
            None => {
                let id = number(range)?;
                (id, id)
            }
        };
        if last < first || u64::from(last) - u64::from(first) + 1 > MAX_CPUS as u64 {
            return None;
        }
        for id in first..=last {
            if ids.len() >= MAX_CPUS || !ids.insert(id) {
                return None;
            }
        }
    }
    Some(ids.into_iter().collect())
}

fn one_field<'a>(raw: &'a str, wanted: &str) -> Option<&'a str> {
    let mut values = raw.lines().filter_map(|line| {
        let (key, value) = line.split_once(':')?;
        (key.trim() == wanted).then_some(value.trim())
    });
    let value = values.next()?;
    (values.next().is_none() && !value.is_empty()).then_some(value)
}

fn numeric(raw: &str) -> Option<u32> {
    if let Some(hex) = raw.strip_prefix("0x") {
        (!hex.is_empty() && hex.len() <= 8 && hex.bytes().all(|b| b.is_ascii_hexdigit()))
            .then(|| u32::from_str_radix(hex, 16).ok())
            .flatten()
    } else {
        (!raw.is_empty() && raw.len() <= 10 && raw.bytes().all(|b| b.is_ascii_digit()))
            .then(|| raw.parse().ok())
            .flatten()
    }
}

fn midr(raw: &str) -> Option<Value> {
    let hex = raw.trim().strip_prefix("0x")?;
    if hex.is_empty() || hex.len() > 16 || !hex.bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    let raw_value = u64::from_str_radix(hex, 16).ok()?;
    let value = u32::try_from(raw_value).ok()?;
    let implementer = value >> 24;
    let part = (value >> 4) & 0xfff;
    if implementer == 0 || part == 0 {
        return None;
    }
    Some(
        json!({"basis":"guest-visible-sysfs-midr-el1","midr_el1":format!("0x{value:08x}"),
        "implementer":implementer,"variant":(value >> 20)&15,
        "architecture":(value >> 16)&15,"part":part,"revision":value&15}),
    )
}

fn cpuinfo(raw: &str) -> Option<BTreeMap<u32, Value>> {
    let mut processors = BTreeMap::new();
    for stanza in raw.split("\n\n") {
        if stanza.trim().is_empty() {
            continue;
        }
        let Some(id) = one_field(stanza, "processor").and_then(numeric) else {
            // Global Hardware/Revision fields are not per-CPU identification.
            if stanza.lines().any(|line| {
                line.split_once(':').is_some_and(|(k, _)| {
                    [
                        "processor",
                        "model name",
                        "CPU implementer",
                        "CPU part",
                        "CPU variant",
                        "CPU revision",
                    ]
                    .contains(&k.trim())
                })
            }) {
                return None;
            }
            continue;
        };
        for key in [
            "model name",
            "CPU implementer",
            "CPU architecture",
            "CPU variant",
            "CPU part",
            "CPU revision",
        ] {
            if stanza
                .lines()
                .filter_map(|line| line.split_once(':'))
                .filter(|(k, _)| k.trim() == key)
                .count()
                > 1
            {
                return None;
            }
        }
        let model = one_field(stanza, "model name").filter(|s| {
            !["aarch64", "arm64", "armv8", "unknown", "generic"]
                .contains(&s.to_ascii_lowercase().as_str())
                && !s.to_ascii_lowercase().starts_with("armv")
                && !s.to_ascii_lowercase().starts_with("aarch64 processor")
                && s.len() <= 4096
        });
        let tuple = (|| {
            let implementer = numeric(one_field(stanza, "CPU implementer")?)?;
            let part = numeric(one_field(stanza, "CPU part")?)?;
            let variant = numeric(one_field(stanza, "CPU variant")?)?;
            let revision = numeric(one_field(stanza, "CPU revision")?)?;
            let architecture = one_field(stanza, "CPU architecture")?;
            if implementer == 0
                || implementer > 255
                || part == 0
                || part > 4095
                || variant > 15
                || revision > 15
                || architecture.len() > 32
            {
                return None;
            }
            Some(
                json!({"basis":"guest-visible-proc-cpuinfo-arm-tuple","implementer":implementer,
                "part":part,"variant":variant,"revision":revision,"architecture":architecture}),
            )
        })();
        let identity = tuple
            .or_else(|| {
                model.map(|s| json!({"basis":"guest-visible-proc-cpuinfo-model","model_name":s}))
            })
            .unwrap_or(Value::Null);
        if processors.len() >= MAX_CPUS || processors.insert(id, identity).is_some() {
            return None;
        }
    }
    Some(processors)
}

fn os_release_identity(receipt: &Value) -> Option<Value> {
    let raw = text(receipt)?;
    let field = |name: &str| {
        let mut values = raw
            .lines()
            .filter_map(|line| line.split_once('='))
            .filter(|(key, _)| *key == name)
            .map(|(_, value)| value.trim_matches('"'));
        let value = values.next()?;
        (!value.is_empty()
            && values.next().is_none()
            && value
                .bytes()
                .all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b)))
        .then_some(value)
    };
    Some(json!({"id":field("ID")?,"version_id":field("VERSION_ID")?,
        "raw_sha256":receipt["sha256"],"resolved_source":receipt["path"]}))
}

/// Capture the parent context in process. Child FSIZE/CORE overrides are
/// declared separately; these are not samples of continuously running workers.
pub(super) fn capture(
    proc: &Path,
    sys: &Path,
    release: &Path,
    image: [Option<String>; 2],
) -> Value {
    let mut reader = Reader {
        remaining: RAW_BUDGET,
        sources: Vec::new(),
    };
    let mut unavailable = Vec::<String>::new();
    let status = reader.read(&proc.join("thread-self/status"), 32_768);
    let affinity = text(&status)
        .and_then(|s| one_field(s, "Cpus_allowed_list"))
        .and_then(cpu_list);
    let memory_affinity = text(&status)
        .and_then(|s| one_field(s, "Mems_allowed_list"))
        .and_then(cpu_list);
    if affinity.is_none() || memory_affinity.is_none() {
        unavailable.push("current-thread CPU/memory affinity unavailable or malformed".into());
    }
    let info_receipt = reader.read(&proc.join("cpuinfo"), 524_288);
    let info = text(&info_receipt).and_then(cpuinfo);
    if text(&info_receipt).is_some() && info.is_none() {
        unavailable.push("ambiguous or malformed per-processor cpuinfo stanzas".into());
    }
    let mut cpus = Vec::new();
    for id in affinity.as_deref().unwrap_or(&[]) {
        let receipt = reader.read(
            &sys.join(format!(
                "devices/system/cpu/cpu{id}/regs/identification/midr_el1"
            )),
            128,
        );
        let observed = match text(&receipt) {
            Some(raw) => midr(raw),
            None => info
                .as_ref()
                .and_then(|rows| rows.get(id))
                .filter(|v| !v.is_null())
                .cloned(),
        };
        let fallback = info
            .as_ref()
            .and_then(|rows| rows.get(id))
            .filter(|v| !v.is_null());
        let conflict = observed.as_ref().zip(fallback).is_some_and(|(a, b)| {
            ["implementer", "variant", "part", "revision"]
                .iter()
                .any(|k| !a[k].is_null() && !b[k].is_null() && a[k] != b[k])
        });
        if observed.is_none() || conflict {
            unavailable.push(format!(
                "substantive identification unavailable or conflicting for affinity CPU {id}"
            ));
        }
        cpus.push(
            json!({"cpu":id,"identification":observed,"cpuinfo_observation":fallback,
            "conflicting_identification":conflict}),
        );
    }
    let mut kernel = serde_json::Map::new();
    for (name, path) in [
        ("ostype", "sys/kernel/ostype"),
        ("release", "sys/kernel/osrelease"),
        ("version", "sys/kernel/version"),
        ("boot_id", "sys/kernel/random/boot_id"),
    ] {
        let receipt = reader.read(&proc.join(path), 4096);
        let value = text(&receipt)
            .map(str::trim_end)
            .filter(|s| !s.is_empty() && !s.bytes().any(|b| matches!(b, 0 | b'\n' | b'\r')));
        if value.is_none() {
            unavailable.push(format!("kernel {name} unavailable"));
        }
        kernel.insert(name.into(), json!(value));
    }
    if kernel["ostype"] != "Linux" {
        unavailable.push("observed kernel is not Linux".into());
    }
    let release_receipt = match release.canonicalize() {
        Ok(resolved) => reader.read(&resolved, 65_536),
        Err(error) => json!({"path":release,"status":"unavailable","error":error.to_string()}),
    };
    let release_identity = os_release_identity(&release_receipt);
    if release_identity.is_none() {
        unavailable.push("guest userspace release unavailable".into());
    }
    let resources = resources::capture(proc, &mut |path, cap| reader.read(path, cap));
    if resources["complete"] != true {
        unavailable.push("visible resource context incomplete".into());
    }
    let identity = json!({"cpu_affinity":affinity,"memory_affinity":memory_affinity,"processors":cpus,
        "kernel":kernel,"guest_userspace_release":release_identity,
        "declared_ci_image":{"image_os":image[0],"image_version":image[1],"independently_attested":false},
        "resources":resources["static_identity"],
        "worker_resource_overrides":{"RLIMIT_FSIZE":65536,"RLIMIT_CORE":0,
            "scope":"fixed direct-child overrides; remaining parent limits and affinity are inherited, not continuously observed"}});
    let bytes = serde_json::to_vec(&identity).expect("JSON value serializes");
    json!({"schema_version":1,"scope":"controller and inherited worker context within guest-visible namespaces",
        "outer_host_limits":"unknown","physical_cpu_identity":"unknown",
        "complete":unavailable.is_empty(),"static_identity":identity,"static_sha256":super::digest(&bytes),
        "raw_sources":reader.sources,"resources":resources,"release_source":release_receipt,
        "raw_input_budget_bytes":RAW_BUDGET,"raw_input_budget_remaining":reader.remaining,
        "unavailable_reasons":unavailable})
}

fn qualified(context: &Value) -> bool {
    let identity = &context["static_identity"];
    let Some(affinity) = identity["cpu_affinity"]
        .as_array()
        .filter(|v| !v.is_empty() && v.len() <= MAX_CPUS)
    else {
        return false;
    };
    let Some(processors) = identity["processors"]
        .as_array()
        .filter(|v| v.len() == affinity.len())
    else {
        return false;
    };
    let mut previous = None;
    for (id, row) in affinity.iter().zip(processors) {
        let Some(cpu) = id.as_u64().filter(|v| *v <= u32::MAX as u64) else {
            return false;
        };
        if previous.is_some_and(|p| p >= cpu)
            || row["cpu"] != *id
            || row["conflicting_identification"] != false
        {
            return false;
        }
        previous = Some(cpu);
        let observed = &row["identification"];
        let valid_cpu = match observed["basis"].as_str() {
            Some("guest-visible-sysfs-midr-el1") => observed["midr_el1"]
                .as_str()
                .and_then(midr)
                .is_some_and(|decoded| decoded == *observed),
            Some("guest-visible-proc-cpuinfo-arm-tuple") => {
                [
                    ("implementer", 1, 255),
                    ("part", 1, 4095),
                    ("variant", 0, 15),
                    ("revision", 0, 15),
                ]
                .iter()
                .all(|(key, min, max)| {
                    observed[key]
                        .as_u64()
                        .is_some_and(|v| (*min..=*max).contains(&v))
                }) && observed["architecture"]
                    .as_str()
                    .is_some_and(|v| !v.is_empty() && v.len() <= 32)
            }
            Some("guest-visible-proc-cpuinfo-model") => {
                observed["model_name"].as_str().is_some_and(|name| {
                    let lower = name.to_ascii_lowercase();
                    !name.is_empty()
                        && name.len() <= 4096
                        && !["aarch64", "arm64", "armv8", "unknown", "generic"]
                            .contains(&lower.as_str())
                        && !lower.starts_with("armv")
                        && !lower.starts_with("aarch64 processor")
                })
            }
            _ => false,
        };
        if !valid_cpu {
            return false;
        }
    }
    let resource = &context["resources"];
    let release = &context["release_source"];
    let source_bound = text(release).is_some_and(|raw| {
        super::digest(raw.as_bytes()) == identity["guest_userspace_release"]["raw_sha256"]
            && os_release_identity(release).as_ref() == Some(&identity["guest_userspace_release"])
    });
    context["schema_version"] == 1
        && context["complete"] == true
        && context["unavailable_reasons"]
            .as_array()
            .is_some_and(Vec::is_empty)
        && resource["complete"] == true
        && resource["static_identity"] == identity["resources"]
        && identity["kernel"]["ostype"] == "Linux"
        && ["release", "version", "boot_id"].iter().all(|key| {
            identity["kernel"][key]
                .as_str()
                .is_some_and(|v| !v.is_empty() && v.len() <= 4096)
        })
        && identity["memory_affinity"]
            .as_array()
            .is_some_and(|v| !v.is_empty() && v.len() <= MAX_CPUS)
        && identity["worker_resource_overrides"]["RLIMIT_FSIZE"] == 65536
        && identity["worker_resource_overrides"]["RLIMIT_CORE"] == 0
        && source_bound
        && super::digest(&serde_json::to_vec(identity).expect("JSON value serializes"))
            == context["static_sha256"]
}

pub(super) fn comparison(before: &Value, after: &Value) -> Value {
    let valid = |v: &Value| qualified(v);
    let ordered = before["interval_start_ns"]
        .as_u64()
        .zip(before["interval_end_ns"].as_u64())
        .zip(
            after["interval_start_ns"]
                .as_u64()
                .zip(after["interval_end_ns"].as_u64()),
        )
        .is_some_and(|((a, b), (c, d))| a <= b && b < c && c <= d);
    let complete = valid(before) && valid(after) && ordered;
    let equal = complete && before["static_identity"] == after["static_identity"];
    json!({"complete":complete,"static_identity_equal":equal,"endpoint_intervals_ordered":ordered,
        "status":if !complete {"unavailable"} else if equal {"stable_guest_visible_context"} else {"guest_visible_context_drift"},
        "outer_host_limits":"unknown","physical_cpu_identity":"unknown"})
}

pub(super) fn cpu_interval(before: &Value, after: &Value) -> Value {
    let mut interval = resources::cpu_interval(&before["resources"], &after["resources"]);
    let ordered = comparison(before, after)["endpoint_intervals_ordered"] == true;
    if !ordered {
        interval["qualified"] = json!(false);
        interval["status"] = json!("partial_unordered_endpoints");
        interval["ancestors"] = Value::Null;
    }
    interval["endpoint_intervals_ordered"] = json!(ordered);
    interval
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{fs, path::PathBuf};

    #[test]
    fn completion_requires_every_visible_cgroup_usage_and_throttling_interval() {
        let f = Fixture::new();
        let before = f.endpoint(10);
        let after = f.endpoint(20);
        assert_eq!(comparison(&before, &after)["static_identity_equal"], true);
        assert_eq!(
            super::super::final_control_status("control_observations_complete", &before, &after),
            "partial_control_cgroup_cpu_interval_unavailable"
        );
        // Missing counters do not become zero, even with valid OS CPU ticks.
        // Equal, explicitly captured zero counters are valid observations.
        for prefix in ["cgroup", "cgroup/leaf"] {
            f.put(&format!("{prefix}/cpu.stat"),
                "usage_usec 0\nuser_usec 0\nsystem_usec 0\nnr_periods 0\nnr_throttled 0\nthrottled_usec 0\n");
        }
        let before = f.endpoint(30);
        let after = f.endpoint(40);
        assert_eq!(
            super::super::final_control_status("control_observations_complete", &before, &after),
            "control_observations_complete"
        );
        fs::remove_file(f.0.join("cgroup/cpu.stat")).unwrap();
        let absent_parent = f.endpoint(50);
        assert_eq!(
            super::super::final_control_status(
                "control_observations_complete",
                &before,
                &absent_parent
            ),
            "partial_control_cgroup_cpu_interval_unavailable"
        );
    }

    #[test]
    fn cgroup_cpu_deltas_preserve_parent_throttling_without_changing_static_identity() {
        let f = Fixture::new();
        let first = "usage_usec 100\nuser_usec 200\nsystem_usec 300\nnr_periods 0\nnr_throttled 0\nthrottled_usec 0\n";
        for prefix in ["cgroup", "cgroup/leaf"] {
            f.put(&format!("{prefix}/cpu.stat"), first);
        }
        let before = f.endpoint(10);
        f.put("cgroup/cpu.stat", "usage_usec 110\nuser_usec 201\nsystem_usec 302\nnr_periods 4\nnr_throttled 2\nthrottled_usec 700\n");
        let after = f.endpoint(20);
        assert_eq!(before["static_sha256"], after["static_sha256"]);
        let interval = cpu_interval(&before, &after);
        assert_eq!(interval["qualified"], true);
        assert_eq!(interval["ancestors"][0]["deltas"]["throttled_usec"], 0);
        assert_eq!(interval["ancestors"][1]["deltas"]["throttled_usec"], 700);
        assert_eq!(interval["ancestors"][1]["deltas"]["usage_usec"], 10);
        assert_eq!(interval["outer_host_counters"], "unknown");
        // The accounting fields need not add up, and multicore/throttling
        // counters need not be bounded by this fixture's wall interval.
        assert_eq!(
            super::super::final_control_status("control_observations_complete", &before, &after),
            "control_observations_complete"
        );
        assert_eq!(cpu_interval(&before, &f.endpoint(10))["qualified"], false);
    }

    #[test]
    fn cgroup_cpu_missing_malformed_duplicate_overflow_reset_and_optional_drift_are_partial() {
        let f = Fixture::new();
        let good = "usage_usec 100\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\nthrottled_usec 60\n";
        for prefix in ["cgroup", "cgroup/leaf"] {
            f.put(&format!("{prefix}/cpu.stat"), good);
        }
        let before = f.endpoint(10);
        for raw in [
            "",
            "usage_usec 100\nuser_usec 20\nsystem_usec 30\n",
            "usage_usec 100\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\n",
            "usage_usec -1\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\nthrottled_usec 60\n",
            "usage_usec 18446744073709551616\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\nthrottled_usec 60\n",
            "usage_usec 100\nusage_usec 100\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\nthrottled_usec 60\n",
            "usage_usec 100 extra\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 5\nthrottled_usec 60\n",
            "usage_usec 100\nuser_usec 20\nsystem_usec 30\nnr_periods 40\nnr_throttled 4\nthrottled_usec 60\n",
        ] {
            // Only the parent fails; a leaf-only predicate would pass.
            f.put("cgroup/cpu.stat", raw);
            let after = f.endpoint(20);
            assert_eq!(
                super::super::final_control_status(
                    "control_observations_complete",
                    &before,
                    &after
                ),
                "partial_control_cgroup_cpu_interval_unavailable",
                "{raw:?}"
            );
            assert_eq!(cpu_interval(&before, &after)["ancestors"], Value::Null);
        }
        f.put("cgroup/cpu.stat", &format!("{good}nr_bursts 1\n"));
        assert_eq!(cpu_interval(&before, &f.endpoint(20))["qualified"], false);
        let optional_before = f.endpoint(30);
        f.put("cgroup/cpu.stat", &format!("{good}nr_bursts 2\n"));
        assert_eq!(
            cpu_interval(&optional_before, &f.endpoint(40))["ancestors"][1]["deltas"]["nr_bursts"],
            1
        );
        f.put("cgroup/cpu.stat", &"x".repeat(4097));
        assert_eq!(cpu_interval(&before, &f.endpoint(50))["qualified"], false);
    }

    #[test]
    fn cgroup_cpu_receipts_cannot_detach_from_the_validated_map_or_raw_bytes() {
        let f = Fixture::new();
        for prefix in ["cgroup", "cgroup/leaf"] {
            f.put(&format!("{prefix}/cpu.stat"), "usage_usec 0\nuser_usec 0\nsystem_usec 0\nnr_periods 0\nnr_throttled 0\nthrottled_usec 0\n");
        }
        let before = f.endpoint(10);
        let after = f.endpoint(20);
        for variant in 0..7 {
            let mut bad = after.clone();
            let rows = &mut bad["resources"]["cpu_counters"];
            match variant {
                0 => *rows = json!([]),
                1 => {
                    rows.as_array_mut().unwrap().pop();
                }
                2 => rows[0]["directory"] = json!("/unrelated"),
                3 => rows[0]["source"]["path"] = json!("/unrelated/cpu.stat"),
                4 => rows[0]["source"]["sha256"] = json!("0".repeat(64)),
                5 => rows[0]["source"]["bytes"] = json!(0),
                _ => rows[0]["source"]["status"] = json!("unavailable"),
            }
            assert_eq!(cpu_interval(&before, &bad)["qualified"], false, "{variant}");
        }
    }

    struct Fixture(PathBuf);
    impl Fixture {
        fn new() -> Self {
            use std::sync::atomic::{AtomicU64, Ordering};
            static ID: AtomicU64 = AtomicU64::new(0);
            let root = std::env::temp_dir().join(format!(
                "nntmux-host-{}-{}-{}",
                std::process::id(),
                std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap()
                    .as_nanos(),
                ID.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&root).unwrap();
            let f = Self(root);
            f.put(
                "proc/thread-self/status",
                "Cpus_allowed_list:\t0,2\nMems_allowed_list:\t0\n",
            );
            f.put("proc/thread-self/cgroup", "0::/leaf\n");
            f.put(
                "proc/self/mountinfo",
                &format!(
                    "29 23 0:26 / {} rw - cgroup2 cgroup rw\n",
                    f.0.join("cgroup").display()
                ),
            );
            f.put("proc/thread-self/limits", concat!(
                "Limit                     Soft Limit           Hard Limit           Units\n",
                "Max cpu time              unlimited            unlimited            seconds\n",
                "Max file size             unlimited            unlimited            bytes\n",
                "Max data size             unlimited            unlimited            bytes\n",
                "Max stack size            8388608              unlimited            bytes\n",
                "Max core file size        0                    unlimited            bytes\n",
                "Max resident set          unlimited            unlimited            bytes\n",
                "Max processes             1024                 2048                 processes\n",
                "Max open files            1024                 4096                 files\n",
                "Max locked memory         8388608              8388608              bytes\n",
                "Max address space         unlimited            unlimited            bytes\n",
                "Max file locks            unlimited            unlimited            locks\n",
                "Max pending signals       1024                 2048                 signals\n",
                "Max msgqueue size         819200               819200               bytes\n",
                "Max nice priority         0                    0\n",
                "Max realtime priority     0                    0\n",
                "Max realtime timeout      unlimited            unlimited            us\n"));
            for prefix in ["cgroup", "cgroup/leaf"] {
                for (name, raw) in [
                    ("cpu.max", "max 100000\n"),
                    ("cpu.weight", "100\n"),
                    ("cpuset.cpus.effective", "0,2\n"),
                    ("cpuset.mems.effective", "0\n"),
                    ("memory.max", "max\n"),
                    ("memory.high", "max\n"),
                    ("memory.swap.max", "max\n"),
                    ("pids.max", "512\n"),
                    ("io.max", ""),
                    ("io.weight", "default 100\n"),
                    ("cgroup.controllers", "cpu cpuset memory pids io\n"),
                    ("cgroup.subtree_control", "cpu cpuset memory pids io\n"),
                ] {
                    f.put(&format!("{prefix}/{name}"), raw);
                }
            }
            for (name, raw) in [
                ("ostype", "Linux\n"),
                ("osrelease", "6.8.0-test\n"),
                ("version", "#1 fixture\n"),
                ("random/boot_id", "12345678-1234-1234-1234-123456789abc\n"),
            ] {
                f.put(&format!("proc/sys/kernel/{name}"), raw);
            }
            f.put("os-release", "ID=ubuntu\nVERSION_ID=\"24.04\"\n");
            f.put(
                "proc/cpuinfo",
                &format!("{}\n{}", stanza(0, 1), stanza(2, 0)),
            );
            f.put(
                "sys/devices/system/cpu/cpu0/regs/identification/midr_el1",
                "0x00000000410fd0c1\n",
            );
            f.put(
                "sys/devices/system/cpu/cpu2/regs/identification/midr_el1",
                "0x00000000410fd0c0\n",
            );
            f
        }
        fn put(&self, path: &str, raw: &str) {
            let path = self.0.join(path);
            fs::create_dir_all(path.parent().unwrap()).unwrap();
            fs::write(path, raw).unwrap();
        }
        fn endpoint(&self, start: u64) -> Value {
            let mut value = capture(
                &self.0.join("proc"),
                &self.0.join("sys"),
                &self.0.join("os-release"),
                [Some("ubuntu24".into()), Some("fixture-v1".into())],
            );
            value["interval_start_ns"] = json!(start);
            value["interval_end_ns"] = json!(start + 1);
            value
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.0).unwrap();
        }
    }

    fn stanza(cpu: u32, revision: u32) -> String {
        format!(
            "processor: {cpu}\nCPU implementer: 0x41\nCPU architecture: 8\nCPU variant: 0x0\nCPU part: 0xd0c\nCPU revision: {revision}\n"
        )
    }

    #[test]
    fn midr_uses_bounded_u64_tokens_without_truncating_extensions_or_zero_revision() {
        for (raw, revision) in [("0x00000000410fd0c1\n", 1), ("0x410fd0c0", 0)] {
            let decoded = midr(raw).unwrap();
            assert_eq!(decoded["implementer"], 65);
            assert_eq!(decoded["part"], 3340);
            assert_eq!(decoded["architecture"], 15);
            assert_eq!(decoded["variant"], 0);
            assert_eq!(decoded["revision"], revision);
        }
        for raw in [
            "",
            "0x",
            "0x00000001410fd0c1",
            "0x410fd0c1 junk",
            "0xfffffffffffffffff",
            "410fd0c1",
        ] {
            assert!(midr(raw).is_none(), "{raw}");
        }
    }

    #[test]
    fn affinity_bounds_cardinality_before_expansion_and_rejects_ambiguity() {
        assert_eq!(cpu_list("0-2,7"), Some(vec![0, 1, 2, 7]));
        assert_eq!(cpu_list("8192"), Some(vec![8192]));
        assert_eq!(cpu_list("0-4095").unwrap().len(), 4096);
        for raw in [
            "0-4096",
            "0-4294967295",
            "2-1",
            "0,,2",
            "-1",
            "1-",
            "",
            "0-2,2",
        ] {
            assert!(cpu_list(raw).is_none(), "{raw}");
        }
        assert!(
            one_field(
                "Cpus_allowed_list: 0\nCpus_allowed_list: 0\n",
                "Cpus_allowed_list"
            )
            .is_none()
        );
    }

    #[test]
    fn cpuinfo_never_inherits_missing_fields_or_silently_collapses_duplicates() {
        let rows = cpuinfo(&format!("{}\n{}", stanza(0, 0), stanza(2, 1))).unwrap();
        assert_eq!(rows[&0]["revision"], 0);
        assert_eq!(rows[&2]["revision"], 1);
        assert_eq!(rows[&0]["architecture"], "8");
        assert!(cpuinfo(&format!("{}\n{}", stanza(2, 0), stanza(2, 0))).is_none());
        assert!(cpuinfo(&(stanza(0, 0) + "CPU revision: 1\n")).is_none());
        assert!(cpuinfo("CPU implementer: 0x41\nCPU part: 0xd0c\n").is_none());
        assert!(cpuinfo("processor: 2\nmodel name: aarch64\n").unwrap()[&2].is_null());
        let missing = stanza(2, 0).replace("CPU part: 0xd0c\n", "");
        assert!(cpuinfo(&missing).unwrap()[&2].is_null());
    }

    #[test]
    fn host_context_requires_complete_cpu_map_and_ignores_dynamic_cpuinfo_fields() {
        let f = Fixture::new();
        let before = f.endpoint(1);
        assert_eq!(before["complete"], true, "{before:#}");
        let stable = f.endpoint(10);
        assert_eq!(comparison(&before, &stable)["static_identity_equal"], true);
        f.put(
            "proc/cpuinfo",
            &format!(
                "{}BogoMIPS: 999.0\n\n{}BogoMIPS: 222.0\n",
                stanza(0, 1),
                stanza(2, 0)
            ),
        );
        assert_eq!(
            comparison(&before, &f.endpoint(20))["static_identity_equal"],
            true
        );
        f.put(
            "sys/devices/system/cpu/cpu2/regs/identification/midr_el1",
            "0x00000000410fd0c1\n",
        );
        let conflicting = f.endpoint(30);
        assert_eq!(conflicting["complete"], false);
        assert_eq!(
            conflicting["static_identity"]["processors"][1]["conflicting_identification"],
            true
        );
        assert_eq!(comparison(&before, &conflicting)["complete"], false);
    }

    #[test]
    fn typed_cpu_map_and_image_resource_drift_invalidate_even_when_cpu_type_multiset_matches() {
        let f = Fixture::new();
        let before = f.endpoint(1);
        f.put(
            "sys/devices/system/cpu/cpu0/regs/identification/midr_el1",
            "0x00000000410fd0c0\n",
        );
        f.put(
            "sys/devices/system/cpu/cpu2/regs/identification/midr_el1",
            "0x00000000410fd0c1\n",
        );
        f.put(
            "proc/cpuinfo",
            &format!("{}\n{}", stanza(2, 1), stanza(0, 0)),
        );
        let swapped = f.endpoint(10);
        assert_eq!(swapped["complete"], true);
        assert_eq!(
            comparison(&before, &swapped)["status"],
            "guest_visible_context_drift"
        );
        let mut forged = before.clone();
        forged["interval_start_ns"] = json!(20);
        forged["interval_end_ns"] = json!(21);
        forged["static_identity"]["declared_ci_image"]["image_version"] = json!("changed");
        forged["stable"] = json!(true);
        assert_eq!(comparison(&before, &forged)["complete"], false);
        forged["static_sha256"] = json!(super::super::digest(
            &serde_json::to_vec(&forged["static_identity"]).unwrap()
        ));
        assert_eq!(
            comparison(&before, &forged)["status"],
            "guest_visible_context_drift"
        );
        assert_eq!(comparison(&before, &before)["complete"], false);
    }

    #[test]
    fn final_completion_cannot_borrow_cpu_counter_success_without_host_evidence() {
        let f = Fixture::new();
        for prefix in ["cgroup", "cgroup/leaf"] {
            f.put(&format!("{prefix}/cpu.stat"), "usage_usec 0\nuser_usec 0\nsystem_usec 0\nnr_periods 0\nnr_throttled 0\nthrottled_usec 0\n");
        }
        let before = f.endpoint(1);
        let after = f.endpoint(10);
        assert_eq!(
            super::super::final_control_status("control_observations_complete", &before, &after),
            "control_observations_complete"
        );
        assert_eq!(
            super::super::final_control_status("partial_control", &before, &after),
            "partial_control"
        );
        assert_eq!(
            super::super::final_control_status(
                "control_observations_complete",
                &before,
                &Value::Null
            ),
            "invalidated_host_context_unavailable"
        );
        assert_eq!(
            super::super::final_control_status("invalidated_identity_drift", &before, &Value::Null),
            "invalidated_identity_drift"
        );
        f.put("cgroup/leaf/cpu.max", "50000 100000\n");
        let constrained = f.endpoint(20);
        assert_eq!(constrained["complete"], true);
        assert_eq!(
            super::super::final_control_status(
                "control_observations_complete",
                &before,
                &constrained
            ),
            "invalidated_host_context_drift"
        );
    }

    #[test]
    fn missing_cpu_identity_or_os_arch_only_never_qualifies_and_raw_budgets_are_explicit() {
        let f = Fixture::new();
        fs::remove_file(f.0.join("sys/devices/system/cpu/cpu2/regs/identification/midr_el1"))
            .unwrap();
        f.put(
            "proc/cpuinfo",
            &format!("{}\nprocessor: 2\nmodel name: aarch64\n", stanza(0, 1)),
        );
        let missing = f.endpoint(1);
        assert_eq!(missing["complete"], false);
        assert_eq!(comparison(&missing, &f.endpoint(10))["complete"], false);
        let fabricated = json!({"schema_version":1,"complete":true,"static_identity":{"os":"linux","arch":"aarch64"}});
        assert_eq!(comparison(&fabricated, &fabricated)["complete"], false);
        let mut reader = Reader {
            remaining: 8,
            sources: Vec::new(),
        };
        let receipt = reader.read(&f.0.join("proc/cpuinfo"), 4096);
        assert_eq!(receipt["status"], "unavailable");
        assert_eq!(reader.remaining, 0);
        assert_eq!(
            reader.read(&f.0.join("os-release"), 4096)["error_kind"],
            "budget_exceeded"
        );
    }
}
