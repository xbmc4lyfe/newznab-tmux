//! Raw CPU evidence for paired benchmark endpoints. Hidden cgroup ancestors
//! remain unavailable; callers must qualify OS counters before comparing them.

use serde_json::{Value, json};
use std::{
    fs,
    path::Path,
    time::{SystemTime, UNIX_EPOCH},
};

#[cfg(not(any(target_os = "linux", target_os = "macos")))]
compile_error!("CPU snapshots support Linux and macOS hosts only");

/// Capture raw OS counters and the cgroup v2 mounted view without inferring
/// limits from missing evidence. All I/O errors remain in the artifact.
/// The trusted caller must supply a UTF-8 proc root for JSON path records.
pub fn snapshot(proc: &Path) -> Value {
    let now = SystemTime::now();
    let nanos = match now.duration_since(UNIX_EPOCH) {
        Ok(value) => value.as_nanos() as i128,
        Err(error) => -(error.duration().as_nanos() as i128),
    };
    let captured_at = utc_timestamp(
        nanos.div_euclid(1_000_000_000) as i64,
        (nanos.rem_euclid(1_000_000_000) / 1000) as u32,
    );
    let monotonic_ns = monotonic_ns();
    let mut records = json!({
        "stat": read_file(&proc.join("stat")),
        "pressure_cpu": read_file(&proc.join("pressure/cpu")),
        "loadavg": read_file(&proc.join("loadavg")),
    });
    let cpu: Vec<_> = lines(records["stat"]["raw"].as_str().unwrap_or(""))
        .find(|line| line.starts_with("cpu "))
        .map(|line| whitespace_fields(line).skip(1).collect())
        .unwrap_or_default();
    let usable = cpu.len() >= 4
        && cpu
            .iter()
            .all(|field| !field.is_empty() && field.bytes().all(|b| b.is_ascii_digit()));
    if !usable {
        records["stat"]["validation_error"] = json!("No usable aggregate CPU tick counters");
    }
    json!({"schema_version": 1, "captured_at": captured_at, "monotonic_ns": monotonic_ns,
           "os_cpu_usable": usable, "os": records, "cgroup_v2": cgroup_snapshot(proc)})
}

fn read_file(path: &Path) -> Value {
    match fs::read_to_string(path) {
        Ok(raw) => {
            json!({"path": path, "status": "captured", "raw": raw.replace("\r\n", "\n").replace('\r', "\n")})
        }
        Err(error) => json!({"path": path, "status": "unavailable", "error": error.to_string()}),
    }
}

// Python splitlines also recognizes these Unicode and control separators.
fn lines(value: &str) -> impl Iterator<Item = &str> {
    value.split([
        '\n', '\r', '\u{b}', '\u{c}', '\u{1c}', '\u{1d}', '\u{1e}', '\u{85}', '\u{2028}',
        '\u{2029}',
    ])
}

fn whitespace_fields(value: &str) -> impl Iterator<Item = &str> {
    value
        .split(|character: char| {
            character.is_whitespace() || ('\u{1c}'..='\u{1f}').contains(&character)
        })
        .filter(|field| !field.is_empty())
}

fn cgroup_snapshot(proc: &Path) -> Value {
    let membership = read_file(&proc.join("self/cgroup"));
    let mountinfo = read_file(&proc.join("self/mountinfo"));
    let mut result = json!({"status": "unavailable", "membership": membership, "mountinfo": mountinfo,
        "above_mount_root": "unavailable", "scope": "Mounted view only; hidden host/namespace ancestors unavailable"});
    let members: Vec<_> = lines(membership["raw"].as_str().unwrap_or(""))
        .filter_map(|line| line.strip_prefix("0::"))
        .collect();
    if members.len() != 1
        || !members[0].starts_with('/')
        || members[0].split('/').any(|part| part == "..")
    {
        result["error"] = json!("No unambiguous supported cgroup v2 membership (v1 unsupported)");
        return result;
    }
    let member = normalize_posix(members[0]);
    let mut selected: Option<(Vec<String>, Vec<String>)> = None;
    for line in lines(mountinfo["raw"].as_str().unwrap_or("")) {
        let Some((left, right)) = line.split_once(" - ") else {
            continue;
        };
        let fields: Vec<_> = whitespace_fields(left).collect();
        if !right.starts_with("cgroup2 ") || fields.len() < 6 {
            continue;
        }
        let root = normalize_posix(&unescape(fields[3]));
        let mount = normalize_posix(&unescape(fields[4]));
        if !absolute(&root)
            || !absolute(&mount)
            || root.iter().any(|part| part == "..")
            || !member.starts_with(&root)
        {
            continue;
        }
        if selected
            .as_ref()
            .is_none_or(|(previous, _)| root.len() < previous.len())
        {
            selected = Some((root, mount));
        }
    }
    let Some((root, mount)) = selected else {
        result["error"] = json!("No matching cgroup v2 mount; namespace mapping is unavailable");
        return result;
    };
    let mut current = mount.clone();
    current.extend_from_slice(&member[root.len()..]);
    let mut ancestors = Vec::new();
    loop {
        let path = posix_string(&current);
        let mut row = json!({"path": path});
        for name in ["cpu.stat", "cpu.stat.local", "cpu.max", "cpu.pressure"] {
            row[name] = read_file(&Path::new(&path).join(name));
        }
        ancestors.push(row);
        if current == mount {
            break;
        }
        current.pop();
    }
    result["status"] = json!("captured");
    result["membership_path"] = json!(posix_string(&member));
    result["mount_root"] = json!(posix_string(&root));
    result["mountpoint"] = json!(posix_string(&mount));
    result["ancestors"] = json!(ancestors);
    result
}

fn unescape(value: &str) -> String {
    let mut chars = value.chars().peekable();
    let mut result = String::with_capacity(value.len());
    while let Some(character) = chars.next() {
        if character == '\\' {
            let digits: Vec<_> = chars.clone().take(3).collect();
            if digits.len() == 3 && digits.iter().all(|c| ('0'..='7').contains(c)) {
                let number = digits
                    .iter()
                    .fold(0, |value, c| value * 8 + (*c as u32 - '0' as u32));
                result.push(char::from_u32(number).expect("three octal digits are valid Unicode"));
                for _ in 0..3 {
                    chars.next();
                }
                continue;
            }
        }
        result.push(character);
    }
    result
}

// PurePosixPath preserves exactly two leading slashes, collapses dots/repeated
// slashes, and retains parent components without resolving symlinks.
fn normalize_posix(value: &str) -> Vec<String> {
    let mut parts = Vec::new();
    if value.starts_with('/') {
        parts.push(
            if value.starts_with("//") && !value.starts_with("///") {
                "//"
            } else {
                "/"
            }
            .to_owned(),
        );
    }
    parts.extend(
        value
            .split('/')
            .filter(|part| !part.is_empty() && *part != ".")
            .map(str::to_owned),
    );
    parts
}
fn absolute(parts: &[String]) -> bool {
    parts
        .first()
        .is_some_and(|part| part == "/" || part == "//")
}
fn posix_string(parts: &[String]) -> String {
    if absolute(parts) {
        format!("{}{}", parts[0], parts[1..].join("/"))
    } else if parts.is_empty() {
        ".".to_owned()
    } else {
        parts.join("/")
    }
}

fn utc_timestamp(seconds: i64, micros: u32) -> String {
    // Inverse Gregorian civil calendar conversion, including pre-epoch dates.
    let days = seconds.div_euclid(86_400) + 719_468;
    let era = days.div_euclid(146_097);
    let day_of_era = days - era * 146_097;
    let year_of_era =
        (day_of_era - day_of_era / 1460 + day_of_era / 36_524 - day_of_era / 146_096) / 365;
    let mut year = year_of_era + era * 400;
    let day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    let month_prime = (5 * day_of_year + 2) / 153;
    let day = day_of_year - (153 * month_prime + 2) / 5 + 1;
    let month = month_prime + if month_prime < 10 { 3 } else { -9 };
    year += i64::from(month <= 2);
    let within_day = seconds.rem_euclid(86_400);
    format!(
        "{year:04}-{month:02}-{day:02}T{:02}:{:02}:{:02}.{micros:06}+00:00",
        within_day / 3600,
        within_day / 60 % 60,
        within_day % 60
    )
}

// Match the linker symbol selected below, including time32 compatibility ABIs.
// Older 32-bit GNU/musl ABIs retain their two-long time32 compatibility symbol.
// GNU x32 uses 64-bit seconds and syscall-long nanoseconds. Native-time64
// 32-bit musl Hexagon and GNU/musl RISC-V use padded long nanos. OpenHarmony
// uses that time64 layout with its explicit header redirect on 32-bit hosts.
#[cfg(all(
    target_os = "linux",
    any(
        target_arch = "x86_64",
        target_arch = "riscv32",
        target_arch = "hexagon",
        target_env = "ohos"
    )
))]
type ClockSeconds = i64;
#[cfg(not(all(
    target_os = "linux",
    any(
        target_arch = "x86_64",
        target_arch = "riscv32",
        target_arch = "hexagon",
        target_env = "ohos"
    )
)))]
type ClockSeconds = std::ffi::c_long;
#[cfg(all(target_os = "linux", target_env = "gnu", target_arch = "x86_64"))]
type ClockNanoseconds = i64;
#[cfg(not(all(target_os = "linux", target_env = "gnu", target_arch = "x86_64")))]
type ClockNanoseconds = std::ffi::c_long;

#[repr(C)]
struct Timespec<Seconds, Nanoseconds, const BEFORE: usize, const AFTER: usize> {
    seconds: Seconds,
    before: [u8; BEFORE],
    nanoseconds: Nanoseconds,
    after: [u8; AFTER],
}
const CLOCK_PADDING: usize = size_of::<ClockSeconds>() - size_of::<ClockNanoseconds>();
const CLOCK_BEFORE: usize = if cfg!(target_endian = "big") {
    CLOCK_PADDING
} else {
    0
};
const CLOCK_AFTER: usize = CLOCK_PADDING - CLOCK_BEFORE;
type ClockTimespec = Timespec<ClockSeconds, ClockNanoseconds, CLOCK_BEFORE, CLOCK_AFTER>;

// Compile-time checks cover the selected x32 ABI even when host tests run on
// another architecture. These are literal glibc layout requirements.
#[cfg(all(
    target_os = "linux",
    target_env = "gnu",
    target_arch = "x86_64",
    target_pointer_width = "32"
))]
const _: () = {
    assert!(size_of::<ClockTimespec>() == 16);
    assert!(std::mem::align_of::<ClockTimespec>() == 8);
    assert!(std::mem::offset_of!(ClockTimespec, nanoseconds) == 8);
};

fn monotonic_ns() -> u64 {
    use std::ffi::c_int;
    unsafe extern "C" {
        #[cfg_attr(
            all(target_env = "ohos", target_pointer_width = "32"),
            link_name = "__clock_gettime64"
        )]
        fn clock_gettime(clock: c_int, result: *mut ClockTimespec) -> c_int;
    }
    #[cfg(target_os = "linux")]
    const CLOCK_MONOTONIC: c_int = 1;
    #[cfg(target_os = "macos")]
    const CLOCK_MONOTONIC: c_int = 8;
    let mut value = ClockTimespec {
        seconds: 0,
        before: [0; CLOCK_BEFORE],
        nanoseconds: 0,
        after: [0; CLOCK_AFTER],
    };
    // SAFETY: the OS writes one ABI-compatible timespec to a valid local pointer.
    // Linux CLOCK_MONOTONIC and macOS CLOCK_UPTIME_RAW are system-wide and
    // match Python monotonic_ns (mach_absolute_time on macOS).
    let status = unsafe { clock_gettime(CLOCK_MONOTONIC, &mut value) };
    assert_eq!(
        status,
        0,
        "OS monotonic clock unavailable: {}",
        std::io::Error::last_os_error()
    );
    value.seconds as u64 * 1_000_000_000 + value.nanoseconds as u64
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        fs,
        path::PathBuf,
        sync::atomic::{AtomicU64, Ordering},
    };
    static NEXT: AtomicU64 = AtomicU64::new(0);
    pub struct Fixture {
        pub root: PathBuf,
        pub proc: PathBuf,
        pub mount: PathBuf,
    }
    impl Fixture {
        pub fn new() -> Self {
            let root = std::env::temp_dir().join(format!(
                "nntmux-cpu-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&root).unwrap();
            let f = Self {
                proc: root.join("proc"),
                mount: root.join("cgroup"),
                root,
            };
            f.write("stat", "cpu  10 2 30 40 5 0 0 0 0 0\ncpu0 10 2 30 40\n");
            f.write("pressure/cpu", "some avg10=0.00 total=12\n");
            f.write("loadavg", "0.01 0.02 0.03 1/90 100\n");
            f.write("self/cgroup", "0::/team/job\n");
            f.mountinfo("/");
            for relative in ["", "team", "team/job"] {
                let path = f.mount.join(relative);
                Self::put(
                    &path.join("cpu.stat"),
                    "usage_usec 100\nnr_throttled 3\nthrottled_usec 5\n",
                );
                Self::put(&path.join("cpu.max"), "100000 100000\n");
                Self::put(&path.join("cpu.pressure"), "some avg10=0.00 total=10\n");
            }
            f
        }
        pub fn put(path: &Path, text: &str) {
            fs::create_dir_all(path.parent().unwrap()).unwrap();
            fs::write(path, text).unwrap();
        }
        pub fn write(&self, path: &str, text: &str) {
            Self::put(&self.proc.join(path), text);
        }
        pub fn mountinfo(&self, root: &str) {
            self.write(
                "self/mountinfo",
                &format!(
                    "29 23 0:26 {root} {} rw - cgroup2 cgroup rw\n",
                    self.mount.display().to_string().replace(' ', "\\040")
                ),
            );
        }
        pub fn data(&self) -> Value {
            snapshot(&self.proc)
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.root).unwrap();
        }
    }

    #[test]
    fn clock_abi_layouts_match_libc_headers() {
        use std::mem::{offset_of, size_of};
        // Explicit C ABI expectations; exercise all shapes on every host.
        type Time32 = Timespec<i32, i32, 0, 0>;
        type X32 = Timespec<i64, i64, 0, 0>;
        type Time64Le = Timespec<i64, i32, 0, 4>;
        type Time64Be = Timespec<i64, i32, 4, 0>;
        assert_eq!(size_of::<Time32>(), 8);
        assert_eq!(offset_of!(Time32, nanoseconds), 4);
        assert_eq!(size_of::<X32>(), 16);
        assert_eq!(offset_of!(X32, nanoseconds), 8);
        assert_eq!(size_of::<Time64Le>(), 16);
        assert_eq!(offset_of!(Time64Le, nanoseconds), 8);
        assert_eq!(size_of::<Time64Be>(), 16);
        assert_eq!(offset_of!(Time64Be, nanoseconds), 12);
        // Literal alignment expectations on the host ABIs that also use the
        // x32 alignment; other targets (e.g. i386/m68k) differ.
        #[cfg(any(
            target_arch = "aarch64",
            target_arch = "x86_64",
            target_arch = "riscv32"
        ))]
        {
            assert_eq!(std::mem::align_of::<Time32>(), 4);
            assert_eq!(std::mem::align_of::<X32>(), 8);
            assert_eq!(std::mem::align_of::<Time64Le>(), 8);
            assert_eq!(std::mem::align_of::<Time64Be>(), 8);
        }
    }

    #[test]
    fn retains_raw_os_and_leaf_through_root_counters() {
        let f = Fixture::new();
        let data = f.data();
        assert_eq!(data["os_cpu_usable"], true);
        assert_eq!(
            data["os"]["stat"]["raw"],
            "cpu  10 2 30 40 5 0 0 0 0 0\ncpu0 10 2 30 40\n"
        );
        assert_eq!(data["os"]["pressure_cpu"]["status"], "captured");
        let cg = &data["cgroup_v2"];
        assert_eq!(cg["status"], "captured");
        let paths: Vec<_> = cg["ancestors"]
            .as_array()
            .unwrap()
            .iter()
            .map(|v| v["path"].as_str().unwrap())
            .collect();
        assert_eq!(
            paths,
            [
                f.mount.join("team/job").to_str().unwrap(),
                f.mount.join("team").to_str().unwrap(),
                f.mount.to_str().unwrap()
            ]
        );
        assert_eq!(cg["ancestors"][1]["cpu.max"]["raw"], "100000 100000\n");
        assert_eq!(
            cg["ancestors"][0]["cpu.stat"]["raw"],
            "usage_usec 100\nnr_throttled 3\nthrottled_usec 5\n"
        );
    }
    #[test]
    fn nonroot_mount_and_namespace_root_keep_hidden_ancestors_unavailable() {
        let mut f = Fixture::new();
        f.mountinfo("/team");
        let data = f.data();
        let cg = &data["cgroup_v2"];
        assert_eq!(
            cg["ancestors"][0]["path"],
            f.mount.join("job").to_str().unwrap()
        );
        assert_eq!(cg["ancestors"].as_array().unwrap().len(), 2);
        assert_eq!(cg["above_mount_root"], "unavailable");
        f.mount = f.root.join("cgroup space");
        f.mountinfo("/");
        f.write("self/cgroup", "0::/\n");
        let data = f.data();
        let cg = &data["cgroup_v2"];
        assert_eq!(cg["ancestors"][0]["path"], f.mount.to_str().unwrap());
        assert_eq!(cg["ancestors"].as_array().unwrap().len(), 1);
    }
    #[test]
    fn invalid_memberships_and_mounts_never_guess_capture() {
        let f = Fixture::new();
        for member in [
            "2:cpu:/team/job\n",
            "garbage\n",
            "0::relative\n",
            "0::/team/../job\n",
            "0::/team/job\n0::/team/job\n",
        ] {
            f.write("self/cgroup", member);
            let data = f.data();
            assert_eq!(data["cgroup_v2"]["status"], "unavailable");
            assert_eq!(data["cgroup_v2"]["membership"]["raw"], member);
        }
        fs::remove_file(f.proc.join("self/cgroup")).unwrap();
        assert_eq!(f.data()["cgroup_v2"]["membership"]["status"], "unavailable");
        f.write("self/cgroup", "0::/team/job\n");
        for root in ["/other", "relative", "/team/../"] {
            f.mountinfo(root);
            let data = f.data();
            assert_eq!(data["cgroup_v2"]["status"], "unavailable");
            assert!(
                data["cgroup_v2"]["error"]
                    .as_str()
                    .unwrap()
                    .contains("mount")
            );
        }
        f.write("self/mountinfo", "malformed - cgroup2\n");
        let data = f.data();
        assert_eq!(data["cgroup_v2"]["status"], "unavailable");
        assert_eq!(
            data["cgroup_v2"]["mountinfo"]["raw"],
            "malformed - cgroup2\n"
        );
    }
    #[test]
    fn optional_and_invalid_utf8_files_preserve_errors_and_raw_newlines() {
        let f = Fixture::new();
        Fixture::put(
            &f.mount.join("team/job/cpu.stat.local"),
            "throttled_usec 17\n",
        );
        fs::remove_file(f.mount.join("cpu.max")).unwrap();
        fs::remove_file(f.mount.join("team/cpu.max")).unwrap();
        fs::remove_file(f.proc.join("pressure/cpu")).unwrap();
        f.write("loadavg", "a\r\nb\rc\n");
        let data = f.data();
        let cg = &data["cgroup_v2"];
        assert_eq!(data["os_cpu_usable"], true);
        assert_eq!(data["os"]["loadavg"]["raw"], "a\nb\nc\n");
        assert_eq!(data["os"]["pressure_cpu"]["status"], "unavailable");
        assert!(data["os"]["pressure_cpu"]["error"].is_string());
        assert_eq!(
            cg["ancestors"][0]["cpu.stat.local"]["raw"],
            "throttled_usec 17\n"
        );
        assert_eq!(cg["ancestors"][2]["cpu.max"]["status"], "unavailable");
        assert_eq!(
            cg["ancestors"][2]["cpu.stat.local"]["status"],
            "unavailable"
        );
        assert!(cg["ancestors"][1]["cpu.max"]["error"].is_string());
        fs::write(f.proc.join("stat"), [255]).unwrap();
        let data = f.data();
        assert_eq!(data["os_cpu_usable"], false);
        assert_eq!(data["os"]["stat"]["status"], "unavailable");
        assert!(data["os"]["stat"]["error"].is_string());
    }
    #[test]
    fn aggregate_requires_first_matching_line_with_four_ascii_numeric_fields() {
        let f = Fixture::new();
        for raw in [
            "cpu no ticks here\n",
            "cpu 1 2 3\n",
            "cpu -1 2 3 4\n",
            "intr 100\n",
            "cpu\t1 2 3 4\n",
            "cpu ١ 2 3 4\n",
            "cpu 1 2 3 4 extra\n",
            "cpu bad\ncpu 1 2 3 4\n",
        ] {
            f.write("stat", raw);
            let data = f.data();
            assert_eq!(data["os_cpu_usable"], false, "{raw}");
            assert!(data["os"]["stat"]["validation_error"].is_string());
        }
        fs::remove_file(f.proc.join("stat")).unwrap();
        assert_eq!(f.data()["os_cpu_usable"], false);
        f.write(
            "stat",
            "intr 1\u{2028}cpu 9999999999999999999999999999999999999999 2 3 4\n",
        );
        assert_eq!(f.data()["os_cpu_usable"], true);
    }
    #[test]
    fn python_control_whitespace_separates_cpu_fields() {
        let f = Fixture::new();
        f.write("stat", "cpu 1\u{1f}2 3 4\n");
        assert_eq!(f.data()["os_cpu_usable"], true);
    }
    #[test]
    fn broadest_visible_mount_wins_and_equal_roots_keep_first_mount() {
        let f = Fixture::new();
        let other = f.root.join("other");
        f.write("self/mountinfo",&format!("1 2 0:1 /team {} rw - cgroup2 x rw\n2 3 0:2 / {} rw - cgroup2 x rw\n3 4 0:3 / {} rw - cgroup2 x rw\n",other.display(),f.mount.display(),other.display()));
        let data = f.data();
        assert_eq!(data["cgroup_v2"]["mountpoint"], f.mount.to_str().unwrap());
        assert_eq!(data["cgroup_v2"]["mount_root"], "/");
    }
    #[test]
    fn utc_dates_and_posix_escapes_preserve_boundary_semantics() {
        for (seconds, micros, expected) in [
            (0, 0, "1970-01-01T00:00:00.000000+00:00"),
            (-1, 999999, "1969-12-31T23:59:59.999999+00:00"),
            (951782400, 123456, "2000-02-29T00:00:00.123456+00:00"),
            (4107542400, 0, "2100-03-01T00:00:00.000000+00:00"),
        ] {
            assert_eq!(utc_timestamp(seconds, micros), expected);
        }
        assert_eq!(unescape(r"a\040b\134c\777d\08z"), "a b\\cǿd\\08z");
        assert_eq!(normalize_posix("//team/./job//"), ["//", "team", "job"]);
        assert_eq!(normalize_posix("///team/../"), ["/", "team", ".."]);
    }
    #[test]
    fn timestamps_are_utc_and_monotonic_increases() {
        let f = Fixture::new();
        let first = f.data();
        let second = f.data();
        assert_eq!(first["schema_version"], 1);
        let timestamp = first["captured_at"].as_str().unwrap();
        assert_eq!(timestamp.len(), 32);
        assert!(timestamp.ends_with("+00:00"));
        assert!(first["monotonic_ns"].as_u64().unwrap() > 0);
        assert!(second["monotonic_ns"].as_u64().unwrap() > first["monotonic_ns"].as_u64().unwrap());
    }
}
