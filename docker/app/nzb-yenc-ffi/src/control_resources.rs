//! Bounded guest-visible resource context for the explicit A/A controller.

use serde_json::{Value, json};
use std::{collections::BTreeMap, path::Path};

const SCOPE: &str =
    "controller inherited limits and all namespace-visible cgroup v2 ancestors; outer host unknown";
const CONTROL_CAP: usize = 4096;
const LIMITS_CAP: usize = 16_384;
const MOUNTINFO_CAP: usize = 65_536;
const MAX_ANCESTORS: usize = 32;
const MAX_MOUNTS: usize = 2048;
const REQUIRED: [&str; 8] = [
    "cpu.max",
    "cpu.weight",
    "cpuset.cpus.effective",
    "cpuset.mems.effective",
    "memory.max",
    "memory.high",
    "memory.swap.max",
    "pids.max",
];
const OPTIONAL: [&str; 4] = [
    "io.max",
    "io.weight",
    "cgroup.controllers",
    "cgroup.subtree_control",
];
const LIMIT_FIELDS: [(&str, &str); 16] = [
    ("Max cpu time", "seconds"),
    ("Max file size", "bytes"),
    ("Max data size", "bytes"),
    ("Max stack size", "bytes"),
    ("Max core file size", "bytes"),
    ("Max resident set", "bytes"),
    ("Max processes", "processes"),
    ("Max open files", "files"),
    ("Max locked memory", "bytes"),
    ("Max address space", "bytes"),
    ("Max file locks", "locks"),
    ("Max pending signals", "signals"),
    ("Max msgqueue size", "bytes"),
    ("Max nice priority", ""),
    ("Max realtime priority", ""),
    ("Max realtime timeout", "us"),
];

fn unsigned(text: &str) -> Option<u64> {
    if text.is_empty() || !text.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    text.parse().ok()
}

fn finite_or_max(text: &str, sentinel: &str) -> Option<Value> {
    if text == sentinel {
        Some(json!(sentinel))
    } else {
        unsigned(text).map(|v| json!(v))
    }
}

fn clean_text(raw: &str) -> bool {
    raw.bytes()
        .all(|b| b.is_ascii_graphic() || matches!(b, b' ' | b'\t' | b'\n'))
}

fn limits(raw: &str) -> Option<Value> {
    if !clean_text(raw) {
        return None;
    }
    let mut lines = raw.lines();
    if lines.next()?.split_ascii_whitespace().collect::<Vec<_>>()
        != ["Limit", "Soft", "Limit", "Hard", "Limit", "Units"]
    {
        return None;
    }
    let mut values = BTreeMap::new();
    for line in lines {
        let (name, unit) = LIMIT_FIELDS.iter().find(|(name, _)| {
            line.strip_prefix(name)
                .is_some_and(|rest| rest.starts_with(char::is_whitespace))
        })?;
        let fields: Vec<_> = line.strip_prefix(name)?.split_ascii_whitespace().collect();
        if fields.len() != if unit.is_empty() { 2 } else { 3 }
            || (!unit.is_empty() && fields[2] != *unit)
        {
            return None;
        }
        let soft = finite_or_max(fields[0], "unlimited")?;
        let hard = finite_or_max(fields[1], "unlimited")?;
        if soft.as_u64().zip(hard.as_u64()).is_some_and(|(a, b)| a > b)
            || (soft == "unlimited" && hard != "unlimited")
        {
            return None;
        }
        if values
            .insert(*name, json!({"soft":soft,"hard":hard,"unit":unit}))
            .is_some()
        {
            return None;
        }
    }
    (values.len() == LIMIT_FIELDS.len()).then(|| json!(values))
}

fn cpuset(text: &str) -> Option<Value> {
    let mut values = Vec::new();
    if text.is_empty() {
        return Some(json!(values));
    }
    let mut previous = None;
    for part in text.split(',') {
        let (start, end) = match part.split_once('-') {
            Some((a, b)) => (unsigned(a)?, unsigned(b)?),
            None => {
                let value = unsigned(part)?;
                (value, value)
            }
        };
        if start > end
            || end > u64::from(u32::MAX)
            || previous.is_some_and(|p| start <= p)
            || end.checked_sub(start)?.checked_add(1)? > (4096 - values.len()) as u64
        {
            return None;
        }
        // Expansion is capped before entering the loop, independent of IDs.
        values.extend(start..=end);
        previous = Some(end);
    }
    Some(json!(values))
}

fn device(text: &str) -> Option<(u32, u32)> {
    let (major, minor) = text.split_once(':')?;
    Some((
        unsigned(major)?.try_into().ok()?,
        unsigned(minor)?.try_into().ok()?,
    ))
}

fn io_max(text: &str) -> Option<Value> {
    let mut devices = BTreeMap::new();
    for line in text.lines() {
        let mut fields = line.split_ascii_whitespace();
        let id = device(fields.next()?)?;
        let mut limits = BTreeMap::new();
        for field in fields {
            let (name, raw) = field.split_once('=')?;
            if !["rbps", "wbps", "riops", "wiops"].contains(&name)
                || limits.insert(name, finite_or_max(raw, "max")?).is_some()
            {
                return None;
            }
        }
        if limits.len() != 4 || devices.insert(id, json!(limits)).is_some() {
            return None;
        }
    }
    Some(json!(
        devices
            .into_iter()
            .map(|((major, minor), limits)| json!({"major":major,"minor":minor,"limits":limits}))
            .collect::<Vec<_>>()
    ))
}

fn io_weight(text: &str) -> Option<Value> {
    let mut values = BTreeMap::new();
    let mut has_default = false;
    for line in text.lines() {
        let fields: Vec<_> = line.split_ascii_whitespace().collect();
        if fields.len() != 2 {
            return None;
        }
        let key = if fields[0] == "default" {
            has_default = true;
            "default".to_owned()
        } else {
            let (major, minor) = device(fields[0])?;
            format!("{major}:{minor}")
        };
        let weight = unsigned(fields[1])?;
        if !(1..=10_000).contains(&weight) || values.insert(key, weight).is_some() {
            return None;
        }
    }
    has_default.then(|| json!(values))
}

fn setting(name: &str, raw: &str) -> Option<Value> {
    if !clean_text(raw) {
        return None;
    }
    let text = raw.trim();
    match name {
        "cpu.max" => {
            let fields: Vec<_> = text.split_ascii_whitespace().collect();
            if fields.len() != 2 {
                return None;
            }
            let quota = finite_or_max(fields[0], "max")?;
            let period = unsigned(fields[1])?;
            if !(1000..=1_000_000).contains(&period) || quota.as_u64() == Some(0) {
                return None;
            }
            Some(json!({"quota":quota,"period_us":period}))
        }
        "cpu.weight" => unsigned(text)
            .filter(|v| (1..=10_000).contains(v))
            .map(|v| json!(v)),
        "memory.max" | "memory.high" | "memory.swap.max" | "pids.max" => finite_or_max(text, "max"),
        "cpuset.cpus.effective" | "cpuset.mems.effective" => cpuset(text),
        "io.max" => io_max(text),
        "io.weight" => io_weight(text),
        "cgroup.controllers" | "cgroup.subtree_control" => {
            let mut names = std::collections::BTreeSet::new();
            for name in text.split_ascii_whitespace() {
                if !name
                    .bytes()
                    .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'_')
                    || !names.insert(name)
                {
                    return None;
                }
            }
            Some(json!(names))
        }
        _ => None,
    }
}

fn absolute(text: &str) -> Option<String> {
    if !text.starts_with('/')
        || text.len() > CONTROL_CAP
        || text.bytes().any(|b| b.is_ascii_control())
    {
        return None;
    }
    if text != "/"
        && text[1..]
            .split('/')
            .any(|part| part.is_empty() || part == "." || part == "..")
    {
        return None;
    }
    Some(text.to_owned())
}

fn unescape(text: &str) -> Option<String> {
    let mut decoded = Vec::new();
    let mut bytes = text.as_bytes().iter().copied();
    while let Some(byte) = bytes.next() {
        if byte != b'\\' {
            decoded.push(byte);
            continue;
        }
        let escaped = [bytes.next()?, bytes.next()?, bytes.next()?];
        decoded.push(match &escaped {
            b"040" => b' ',
            b"011" => b'\t',
            b"012" => b'\n',
            b"134" => b'\\',
            _ => return None,
        });
    }
    String::from_utf8(decoded).ok()
}

fn membership(raw: &str) -> Option<String> {
    let mut unified = None;
    for line in raw.lines() {
        let mut fields = line.splitn(3, ':');
        let id = unsigned(fields.next()?)?;
        let controllers = fields.next()?;
        let path = fields.next()?;
        if id == 0 {
            if !controllers.is_empty() || unified.is_some() {
                return None;
            }
            unified = Some(absolute(path)?);
        }
    }
    unified
}

struct Mapping {
    root: String,
    mount: String,
    directories: Vec<(String, String)>,
}

struct Mount {
    id: u64,
    parent: u64,
    root: String,
    mount: String,
    unified: bool,
}

fn covers(mount: &str, path: &str) -> bool {
    mount == "/"
        || mount == path
        || path
            .strip_prefix(mount)
            .is_some_and(|rest| rest.starts_with('/'))
}

fn mapping(raw: &str, member: &str) -> Option<Mapping> {
    let mut mounts = BTreeMap::new();
    for line in raw.lines() {
        if mounts.len() >= MAX_MOUNTS {
            return None;
        }
        let (left, right) = line.split_once(" - ")?;
        let fields: Vec<_> = left.split_ascii_whitespace().collect();
        let filesystem: Vec<_> = right.split_ascii_whitespace().collect();
        if fields.len() < 6 || filesystem.len() != 3 {
            return None;
        }
        let id = unsigned(fields[0]).filter(|v| *v > 0)?;
        let parent = unsigned(fields[1]).filter(|v| *v > 0)?;
        device(fields[2])?;
        let root = absolute(&unescape(fields[3])?)?;
        let mount = absolute(&unescape(fields[4])?)?;
        if mounts
            .insert(
                id,
                Mount {
                    id,
                    parent,
                    root,
                    mount,
                    unified: filesystem[0] == "cgroup2",
                },
            )
            .is_some()
        {
            return None;
        }
    }
    let mut selected = None;
    for mount in mounts.values().filter(|mount| mount.unified) {
        if !covers(&mount.root, member) {
            continue;
        }
        if selected.replace(mount).is_some() {
            return None;
        }
    }
    let selected = selected?;
    let root = &selected.root;
    let mount = &selected.mount;
    let relative = if root == "/" {
        member.trim_start_matches('/')
    } else if root == member {
        ""
    } else {
        member.strip_prefix(&format!("{root}/"))?
    };
    let mut directories = Vec::new();
    let mut hierarchy = member.to_owned();
    let mut suffix = relative.to_owned();
    loop {
        if directories.len() >= MAX_ANCESTORS {
            return None;
        }
        let directory = if suffix.is_empty() {
            mount.clone()
        } else if mount == "/" {
            format!("/{suffix}")
        } else {
            format!("{mount}/{suffix}")
        };
        if directory.len() > CONTROL_CAP {
            return None;
        }
        directories.push((hierarchy.clone(), directory));
        if suffix.is_empty() {
            break;
        }
        suffix = suffix
            .rsplit_once('/')
            .map_or("".to_owned(), |(parent, _)| parent.to_owned());
        hierarchy = hierarchy
            .rsplit_once('/')
            .map_or("/".to_owned(), |(parent, _)| {
                if parent.is_empty() {
                    "/".to_owned()
                } else {
                    parent.to_owned()
                }
            });
    }
    // Parent mounts (including the root filesystem) are legitimate only when
    // they belong to the selected mount's actual parent chain. Other mounts
    // covering a queried directory or control file can redirect those reads.
    let mut parent_chain = std::collections::BTreeSet::new();
    let mut current = selected.id;
    while let Some(record) = mounts.get(&current) {
        if !parent_chain.insert(current) {
            return None;
        }
        if record.parent == current && record.mount == "/" {
            break;
        }
        current = record.parent;
    }
    for other in mounts.values().filter(|record| record.id != selected.id) {
        if covers(&other.mount, mount) {
            if other.mount == *mount || !parent_chain.contains(&other.id) {
                return None;
            }
            continue;
        }
        for (_, directory) in &directories {
            if covers(&other.mount, directory)
                || REQUIRED.into_iter().chain(OPTIONAL).any(|name| {
                    let path = Path::new(directory).join(name);
                    covers(&other.mount, path.to_str().unwrap())
                })
            {
                return None;
            }
        }
    }
    Some(Mapping {
        root: root.clone(),
        mount: mount.clone(),
        directories,
    })
}

fn source(
    read: &mut impl FnMut(&Path, usize) -> Value,
    path: &Path,
    cap: usize,
    receipts: &mut Vec<Value>,
) -> Result<String, String> {
    let record = read(path, cap);
    let result = if record["status"] == "captured" {
        record["raw"]
            .as_str()
            .filter(|raw| raw.len() <= cap && record["bytes"].as_u64() == Some(raw.len() as u64))
            .map(str::to_owned)
            .ok_or_else(|| "invalid_data_or_budget_exceeded".to_owned())
    } else if record["status"] == "unavailable" {
        Err(record["error_kind"]
            .as_str()
            .unwrap_or("unknown")
            .to_owned())
    } else {
        Err("invalid_reader_status".to_owned())
    };
    receipts.push(record);
    result
}

pub(super) fn capture(proc: &Path, read: &mut impl FnMut(&Path, usize) -> Value) -> Value {
    let mut raw_sources = Vec::new();
    let mut reasons = Vec::new();
    let mut identity =
        json!({"scope":SCOPE,"outer_host_limits":"unknown","physical_hierarchy_root":"unknown"});
    for (relative, key, cap, parse) in [
        (
            "thread-self/limits",
            "process_limits",
            LIMITS_CAP,
            limits as fn(&str) -> Option<Value>,
        ),
        ("thread-self/cgroup", "membership", CONTROL_CAP, |raw| {
            membership(raw).map(|v| json!(v))
        }),
    ] {
        let path = proc.join(relative);
        match source(read, &path, cap, &mut raw_sources) {
            Ok(raw) => match parse(&raw) {
                Some(value) => identity[key] = value,
                None => reasons.push(format!("invalid {}", path.display())),
            },
            Err(error) => reasons.push(format!("unavailable {}: {error}", path.display())),
        }
    }
    let mount_path = proc.join("self/mountinfo");
    let mounts = source(read, &mount_path, MOUNTINFO_CAP, &mut raw_sources);
    let selected = match (mounts, identity["membership"].as_str()) {
        (Ok(raw), Some(member)) => mapping(&raw, member),
        (Err(error), _) => {
            reasons.push(format!("unavailable {}: {error}", mount_path.display()));
            None
        }
        _ => None,
    };
    if let Some(selected) = selected {
        let visible_root = selected.root.clone();
        identity["mount_root"] = json!(selected.root);
        identity["mountpoint"] = json!(selected.mount);
        let mut ancestors = Vec::new();
        for (hierarchy, directory) in selected.directories {
            let mut settings = BTreeMap::new();
            for name in REQUIRED.into_iter().chain(OPTIONAL) {
                let path = Path::new(&directory).join(name);
                let value = match source(read, &path, CONTROL_CAP, &mut raw_sources) {
                    Ok(raw) => match setting(name, &raw) {
                        Some(value) => json!({"status":"captured","value":value}),
                        None => {
                            reasons.push(format!("invalid {}", path.display()));
                            json!({"status":"invalid"})
                        }
                    },
                    Err(error) if error == "not_found" && OPTIONAL.contains(&name) => {
                        json!({"status":"not_found_unknown"})
                    }
                    Err(error)
                        if error == "not_found"
                            && hierarchy == visible_root
                            && !name.starts_with("cpuset.") =>
                    {
                        json!({"status":"not_exposed_at_visible_mount_root_unknown","effective_limit":"unknown"})
                    }
                    Err(error) => {
                        reasons.push(format!("unavailable {}: {error}", path.display()));
                        json!({"status":"unavailable","error_kind":error})
                    }
                };
                settings.insert(name, value);
            }
            ancestors.push(
                json!({"hierarchy_path":hierarchy,"directory":directory,"settings":settings}),
            );
        }
        identity["ancestors"] = json!(ancestors);
    } else {
        reasons.push("no unambiguous bounded cgroup v2 mount/membership mapping".into());
    }
    json!({"complete":reasons.is_empty(),"static_identity":identity,"raw_sources":raw_sources,
        "unavailable_reasons":reasons,"scope":SCOPE})
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::BTreeMap;

    const LIMITS: &str = "Limit                     Soft Limit           Hard Limit           Units\n\
Max cpu time              unlimited            unlimited            seconds\n\
Max file size             unlimited            unlimited            bytes\n\
Max data size             unlimited            unlimited            bytes\n\
Max stack size            8388608              unlimited            bytes\n\
Max core file size        0                    unlimited            bytes\n\
Max resident set          unlimited            unlimited            bytes\n\
Max processes             4096                 4096                 processes\n\
Max open files            1024                 1048576              files\n\
Max locked memory         8388608              8388608              bytes\n\
Max address space         unlimited            unlimited            bytes\n\
Max file locks            unlimited            unlimited            locks\n\
Max pending signals       4096                 4096                 signals\n\
Max msgqueue size         819200               819200               bytes\n\
Max nice priority         0                    0                    \n\
Max realtime priority     0                    0                    \n\
Max realtime timeout      unlimited            unlimited            us\n";

    fn fixture(member: &str) -> BTreeMap<String, String> {
        let mut files = BTreeMap::from([
            ("/proc/thread-self/limits".into(), LIMITS.into()),
            ("/proc/thread-self/cgroup".into(), format!("0::{member}\n")),
            (
                "/proc/self/mountinfo".into(),
                "36 25 0:32 / /sys/fs/cgroup rw,nosuid,nodev,noexec,relatime - cgroup2 cgroup rw\n"
                    .into(),
            ),
        ]);
        let mut path = member;
        loop {
            let directory = format!("/sys/fs/cgroup{}", if path == "/" { "" } else { path });
            for (name, value) in [
                ("cpuset.cpus.effective", "0-3\n"),
                ("cpuset.mems.effective", "0\n"),
                ("cgroup.controllers", "cpu cpuset memory pids\n"),
                ("cgroup.subtree_control", "\n"),
            ] {
                files.insert(format!("{directory}/{name}"), value.into());
            }
            if path != "/" {
                for (name, value) in [
                    ("cpu.max", "max 100000\n"),
                    ("cpu.weight", "100\n"),
                    ("memory.max", "max\n"),
                    ("memory.high", "max\n"),
                    ("memory.swap.max", "0\n"),
                    ("pids.max", "4096\n"),
                ] {
                    files.insert(format!("{directory}/{name}"), value.into());
                }
            }
            if path == "/" {
                break;
            }
            path = path.rsplit_once('/').map_or(
                "/",
                |(parent, _)| {
                    if parent.is_empty() { "/" } else { parent }
                },
            );
        }
        files
    }

    fn snapshot(files: &BTreeMap<String, String>) -> Value {
        capture(Path::new("/proc"), &mut |path, cap| {
            assert!(cap <= 65_536);
            let key = path.to_str().unwrap();
            match files.get(key) {
                Some(raw) if raw.len() <= cap => json!({"status":"captured","path":key,
                    "raw":raw,"bytes":raw.len(),"sha256":"a".repeat(64)}),
                Some(_) => {
                    json!({"status":"unavailable","path":key,"error_kind":"budget_exceeded","error":"oversize"})
                }
                None => {
                    json!({"status":"unavailable","path":key,"error_kind":"not_found","error":"missing"})
                }
            }
        })
    }

    #[test]
    fn visible_root_absence_is_unknown_without_claiming_physical_root_or_unlimited() {
        let value = snapshot(&fixture("/"));
        assert_eq!(value["complete"], true);
        assert_eq!(value["static_identity"]["outer_host_limits"], "unknown");
        assert_eq!(
            value["static_identity"]["physical_hierarchy_root"],
            "unknown"
        );
        let ancestors = value["static_identity"]["ancestors"].as_array().unwrap();
        assert_eq!(ancestors.len(), 1);
        assert_eq!(ancestors[0]["hierarchy_path"], "/");
        assert_eq!(
            ancestors[0]["settings"]["cpu.max"]["status"],
            "not_exposed_at_visible_mount_root_unknown"
        );
        assert_eq!(
            ancestors[0]["settings"]["memory.max"]["status"],
            "not_exposed_at_visible_mount_root_unknown"
        );
        assert_eq!(
            ancestors[0]["settings"]["io.max"]["status"],
            "not_found_unknown"
        );
        assert_eq!(
            ancestors[0]["settings"]["cpuset.cpus.effective"]["value"],
            json!([0, 1, 2, 3])
        );
    }

    #[test]
    fn captures_every_visible_ancestor_and_parent_limits_without_worker_overrides() {
        let value = snapshot(&fixture("/runner/job"));
        assert_eq!(value["complete"], true);
        let identity = &value["static_identity"];
        let paths: Vec<_> = identity["ancestors"]
            .as_array()
            .unwrap()
            .iter()
            .map(|row| row["hierarchy_path"].as_str().unwrap())
            .collect();
        assert_eq!(paths, ["/runner/job", "/runner", "/"]);
        assert_eq!(
            identity["process_limits"]["Max file size"]["soft"],
            "unlimited"
        );
        assert_eq!(identity["process_limits"]["Max core file size"]["soft"], 0);
        assert_eq!(
            identity["ancestors"][0]["settings"]["memory.swap.max"]["value"],
            0
        );
        assert!(
            value["raw_sources"]
                .as_array()
                .unwrap()
                .iter()
                .any(|r| r["path"] == "/proc/thread-self/limits")
        );
    }

    #[test]
    fn nonroot_missing_required_controls_never_become_unlimited() {
        for field in [
            "cpu.max",
            "cpu.weight",
            "memory.max",
            "memory.high",
            "memory.swap.max",
            "pids.max",
            "cpuset.cpus.effective",
            "cpuset.mems.effective",
        ] {
            let mut files = fixture("/job");
            files.remove(&format!("/sys/fs/cgroup/job/{field}"));
            let value = snapshot(&files);
            assert_eq!(value["complete"], false, "{field}");
            assert!(
                value["unavailable_reasons"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .any(|r| r.as_str().unwrap().contains(field))
            );
        }
    }

    #[test]
    fn static_identity_changes_for_limits_and_optional_io_but_not_unread_dynamic_usage() {
        let files = fixture("/job");
        let original = snapshot(&files);
        for (field, raw) in [
            ("cpu.max", "50000 100000\n"),
            ("memory.max", "0\n"),
            ("io.max", "8:0 rbps=max wbps=1024 riops=max wiops=max\n"),
            ("io.weight", "default 100\n8:0 200\n"),
        ] {
            let mut changed = files.clone();
            changed.insert(format!("/sys/fs/cgroup/job/{field}"), raw.into());
            let value = snapshot(&changed);
            assert_eq!(value["complete"], true, "{field}");
            assert_ne!(
                value["static_identity"], original["static_identity"],
                "{field}"
            );
        }
        let mut dynamic = files;
        dynamic.insert(
            "/sys/fs/cgroup/job/cpu.stat".into(),
            "usage_usec 12345\n".into(),
        );
        assert_eq!(
            snapshot(&dynamic)["static_identity"],
            original["static_identity"]
        );
        dynamic.insert(
            "/proc/thread-self/limits".into(),
            LIMITS.replace("1024", "2048"),
        );
        let changed = snapshot(&dynamic);
        assert_eq!(changed["complete"], true);
        assert_eq!(
            changed["static_identity"]["process_limits"]["Max open files"]["soft"],
            2048
        );
        assert_ne!(changed["static_identity"], original["static_identity"]);
    }

    #[test]
    fn empty_present_sets_and_io_are_distinct_from_missing_and_zero_is_preserved() {
        let mut files = fixture("/job");
        files.insert(
            "/sys/fs/cgroup/job/cpuset.cpus.effective".into(),
            "\n".into(),
        );
        files.insert("/sys/fs/cgroup/job/io.max".into(), "\n".into());
        files.insert("/sys/fs/cgroup/job/pids.max".into(), "0\n".into());
        let value = snapshot(&files);
        assert_eq!(value["complete"], true);
        let settings = &value["static_identity"]["ancestors"][0]["settings"];
        assert_eq!(settings["cpuset.cpus.effective"]["value"], json!([]));
        assert_eq!(settings["io.max"]["value"], json!([]));
        assert_eq!(settings["pids.max"]["value"], 0);
        assert_eq!(settings["io.weight"]["status"], "not_found_unknown");
    }

    #[test]
    fn malformed_ambiguous_or_escaping_mount_and_membership_mappings_are_incomplete() {
        let mount = fixture("/job")["/proc/self/mountinfo"].clone();
        for (file, raw) in [
            ("/proc/thread-self/cgroup", "0::/../job\n".to_owned()),
            ("/proc/thread-self/cgroup", "0::/job\n0::/other\n".into()),
            ("/proc/thread-self/cgroup", "0::relative\n".into()),
            ("/proc/self/mountinfo", format!("{mount}{mount}")),
            (
                "/proc/self/mountinfo",
                mount.replace(" / /sys", " /elsewhere /sys"),
            ),
            (
                "/proc/self/mountinfo",
                mount.replace(" /sys/fs/cgroup ", " /sys/../cgroup "),
            ),
            ("/proc/self/mountinfo", "malformed\n".into()),
        ] {
            let mut files = fixture("/job");
            files.insert(file.into(), raw);
            assert_eq!(snapshot(&files)["complete"], false, "{file}");
        }
    }

    #[test]
    fn maps_nonroot_mount_roots_and_escaped_mountpoints_without_inventing_hidden_ancestors() {
        let mut files = fixture("/delegated/job");
        let original = files.clone();
        for (path, raw) in original {
            if let Some(relative) = path.strip_prefix("/sys/fs/cgroup/delegated") {
                files.insert(format!("/mounted view{relative}"), raw);
            }
        }
        files.insert(
            "/proc/self/mountinfo".into(),
            "36 25 0:32 /delegated /mounted\\040view rw - cgroup2 cgroup rw\n".into(),
        );
        let value = snapshot(&files);
        assert_eq!(value["complete"], true);
        assert_eq!(
            value["static_identity"]["ancestors"]
                .as_array()
                .unwrap()
                .len(),
            2
        );
        assert_eq!(
            value["static_identity"]["ancestors"][1]["hierarchy_path"],
            "/delegated"
        );
        assert_eq!(value["static_identity"]["mount_root"], "/delegated");
        files.remove("/mounted view/cpu.max");
        let boundary = snapshot(&files);
        assert_eq!(boundary["complete"], true);
        assert_eq!(
            boundary["static_identity"]["ancestors"][1]["settings"]["cpu.max"]["status"],
            "not_exposed_at_visible_mount_root_unknown"
        );
        assert_eq!(
            boundary["static_identity"]["physical_hierarchy_root"],
            "unknown"
        );
        files.remove("/mounted view/job/cpu.max");
        assert_eq!(snapshot(&files)["complete"], false);
    }

    #[test]
    fn unrelated_cgroup_mount_does_not_hide_the_unique_matching_mount() {
        let mut files = fixture("/job");
        let matching = files["/proc/self/mountinfo"].clone();
        let unrelated = "37 25 0:32 /elsewhere /unrelated rw - cgroup2 cgroup rw\n";
        for raw in [
            format!("{unrelated}{matching}"),
            format!("{matching}{unrelated}"),
        ] {
            files.insert("/proc/self/mountinfo".into(), raw);
            assert_eq!(snapshot(&files)["complete"], true);
        }
    }

    #[test]
    fn covering_and_stacked_mounts_cannot_relabel_the_selected_cgroup_controls() {
        let matching = fixture("/job")["/proc/self/mountinfo"].clone();
        for covering in [
            "37 36 0:32 /other /sys/fs/cgroup/job rw - cgroup2 cgroup rw\n",
            "37 36 0:33 / /sys/fs/cgroup/job rw - tmpfs tmpfs rw\n",
            "37 36 0:32 /other/cpu.max /sys/fs/cgroup/job/cpu.max rw - cgroup2 cgroup rw\n",
            "37 36 0:33 / /sys/fs/cgroup/job/cpu.max rw - tmpfs tmpfs rw\n",
            "37 36 0:32 /other /sys/fs/cgroup rw - cgroup2 cgroup rw\n",
        ] {
            let mut files = fixture("/job");
            files.insert(
                "/proc/self/mountinfo".into(),
                format!("{matching}{covering}"),
            );
            assert_eq!(snapshot(&files)["complete"], false, "{covering}");
        }
        let mut files = fixture("/job");
        files.insert("/proc/self/mountinfo".into(), format!(
            "25 1 0:1 / / rw - ext4 /dev/root rw\n{matching}37 36 0:33 / /sys/fs/cgroup/unread rw - tmpfs tmpfs rw\n"));
        assert_eq!(snapshot(&files)["complete"], true);
    }

    #[test]
    fn invalid_reader_status_or_inconsistent_byte_count_never_becomes_allowed_absence() {
        for invalid in [
            json!({"status":"unexpected","error_kind":"not_found"}),
            json!({"status":"captured","raw":"0-3\n","bytes":1}),
        ] {
            let files = fixture("/");
            let value = capture(Path::new("/proc"), &mut |path, _| {
                if path.ends_with("io.max") {
                    return invalid.clone();
                }
                match files.get(path.to_str().unwrap()) {
                    Some(raw) => {
                        json!({"status":"captured","raw":raw,"path":path,"bytes":raw.len()})
                    }
                    None => json!({"status":"unavailable","path":path,"error_kind":"not_found"}),
                }
            });
            assert_eq!(value["complete"], false, "{invalid}");
        }
    }

    #[test]
    fn oversized_inputs_and_more_than_32_ancestors_are_incomplete_without_extra_reads() {
        let mut files = fixture("/job");
        files.insert("/proc/self/mountinfo".into(), "x".repeat(65_537));
        assert_eq!(snapshot(&files)["complete"], false);
        let member = format!("/{}", vec!["a"; 32].join("/"));
        let mut files = fixture("/");
        files.insert("/proc/thread-self/cgroup".into(), format!("0::{member}\n"));
        let mut calls = 0;
        let value = capture(Path::new("/proc"), &mut |path, cap| {
            calls += 1;
            assert!(cap <= 65_536);
            files.get(path.to_str().unwrap()).map_or(
                json!({"status":"unavailable","error_kind":"not_found"}),
                |raw| json!({"status":"captured","raw":raw,"path":path,"bytes":raw.len()}),
            )
        });
        assert_eq!(value["complete"], false);
        assert_eq!(calls, 3);
    }

    fn mountinfo_with_length(length: usize) -> String {
        let mut raw = fixture("/job")["/proc/self/mountinfo"].clone();
        let mut id = 100;
        while length - raw.len() > 180 {
            raw.push_str(&format!("{id} 25 0:1 / /other/{id} rw - tmpfs tmpfs rw\n"));
            id += 1;
        }
        let prefix = format!("{id} 25 0:1 / /other/{id} rw,fixture=");
        let suffix = " - tmpfs tmpfs rw\n";
        let padding = length - raw.len() - prefix.len() - suffix.len();
        raw.push_str(&prefix);
        raw.push_str(&"a".repeat(padding));
        raw.push_str(suffix);
        assert_eq!(raw.len(), length);
        raw
    }

    #[test]
    fn proc_documents_have_separate_finite_caps_without_widening_control_reads() {
        for (mount_bytes, complete) in [(4097, true), (65_536, true), (65_537, false)] {
            let mut files = fixture("/job");
            files.insert(
                "/proc/self/mountinfo".into(),
                mountinfo_with_length(mount_bytes),
            );
            assert_eq!(
                snapshot(&files)["complete"],
                complete,
                "mountinfo={mount_bytes}"
            );
        }
        for (limit_bytes, complete) in [(16_384, true), (16_385, false)] {
            let mut files = fixture("/job");
            files.insert(
                "/proc/thread-self/limits".into(),
                LIMITS.replacen(
                    "Limit",
                    &format!("Limit{}", " ".repeat(limit_bytes - LIMITS.len())),
                    1,
                ),
            );
            assert_eq!(
                snapshot(&files)["complete"],
                complete,
                "limits={limit_bytes}"
            );
        }
        let files = fixture("/job");
        let mut observed = BTreeMap::new();
        let value = capture(Path::new("/proc"), &mut |path, cap| {
            let key = path.to_str().unwrap();
            observed.insert(key.to_owned(), cap);
            match files.get(key) {
                Some(raw) => json!({"status":"captured","path":path,"raw":raw,"bytes":raw.len()}),
                None => json!({"status":"unavailable","path":path,"error_kind":"not_found"}),
            }
        });
        assert_eq!(value["complete"], true);
        for (path, cap) in observed {
            let expected = match path.as_str() {
                "/proc/self/mountinfo" => 65_536,
                "/proc/thread-self/limits" => 16_384,
                _ => 4096,
            };
            assert_eq!(cap, expected, "{path}");
        }
        let mut oversized_control = files;
        oversized_control.insert("/sys/fs/cgroup/job/io.max".into(), " ".repeat(4097));
        assert_eq!(snapshot(&oversized_control)["complete"], false);
    }

    #[test]
    fn quotas_weights_cpuset_and_io_values_are_checked_without_limits_based_allocations() {
        for (field, raw) in [
            ("cpu.max", "0 100000\n"),
            ("cpu.max", "max 0\n"),
            ("cpu.max", "50000\n"),
            ("cpu.weight", "0\n"),
            ("cpu.weight", "10001\n"),
            ("memory.max", "18446744073709551616\n"),
            ("pids.max", "-1\n"),
            ("cpuset.cpus.effective", "0-4294967295\n"),
            ("cpuset.cpus.effective", "0-3,2\n"),
            ("cpuset.cpus.effective", "3-1\n"),
            ("cpuset.mems.effective", "1,,2\n"),
            (
                "io.max",
                "8:0 rbps=1 wbps=2 riops=3 wiops=4\n8:0 rbps=5 wbps=6 riops=7 wiops=8\n",
            ),
            ("io.weight", "default 0\n"),
        ] {
            let mut files = fixture("/job");
            files.insert(format!("/sys/fs/cgroup/job/{field}"), raw.into());
            assert_eq!(snapshot(&files)["complete"], false, "{field}={raw}");
        }
        let mut files = fixture("/job");
        files.insert(
            "/sys/fs/cgroup/job/cpuset.cpus.effective".into(),
            "0-4095\n".into(),
        );
        assert_eq!(snapshot(&files)["complete"], true);
        files.insert(
            "/sys/fs/cgroup/job/cpuset.cpus.effective".into(),
            "0-4096\n".into(),
        );
        assert_eq!(snapshot(&files)["complete"], false);
        files.insert(
            "/sys/fs/cgroup/job/cpuset.cpus.effective".into(),
            "4294967295\n".into(),
        );
        files.insert("/sys/fs/cgroup/job/cpu.max".into(), "50000 100000\n".into());
        files.insert(
            "/sys/fs/cgroup/job/memory.max".into(),
            "18446744073709551615\n".into(),
        );
        let value = snapshot(&files);
        assert_eq!(value["complete"], true);
        assert_eq!(
            value["static_identity"]["ancestors"][0]["settings"]["cpu.max"]["value"],
            json!({"quota":50000,"period_us":100000})
        );
        assert_eq!(
            value["static_identity"]["ancestors"][0]["settings"]["memory.max"]["value"].as_u64(),
            Some(u64::MAX)
        );
    }

    #[test]
    fn missing_thread_self_evidence_never_silently_uses_process_leader_limits() {
        let mut files = fixture("/job");
        files.remove("/proc/thread-self/limits");
        files.insert("/proc/self/limits".into(), LIMITS.into());
        assert_eq!(snapshot(&files)["complete"], false);
        for raw in [
            "",
            "Limit Soft Hard Units\n",
            "garbage\n",
            &LIMITS.replace("8388608", "18446744073709551616"),
        ] {
            files.insert("/proc/thread-self/limits".into(), raw.into());
            assert_eq!(snapshot(&files)["complete"], false);
        }
    }

    #[test]
    fn unreadable_or_budget_failed_optional_and_root_files_are_not_accepted_as_absent() {
        for kind in ["permission_denied", "budget_exceeded", "unknown"] {
            let files = fixture("/");
            let value = capture(Path::new("/proc"), &mut |path, _| match files
                .get(path.to_str().unwrap())
            {
                Some(raw) => json!({"status":"captured","raw":raw,"path":path,"bytes":raw.len()}),
                None => {
                    json!({"status":"unavailable","path":path,"error_kind":kind,"error":"failed"})
                }
            });
            assert_eq!(value["complete"], false, "{kind}");
        }
    }
}
