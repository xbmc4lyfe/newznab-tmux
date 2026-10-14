//! Write one exclusive CPU evidence endpoint for paired benchmark intervals.
use std::{
    ffi::OsString,
    fs::OpenOptions,
    io::{self, BufWriter, Write},
    path::PathBuf,
};

fn run(mut args: impl Iterator<Item = OsString>) -> i32 {
    let mut output = None;
    let mut proc = PathBuf::from("/proc");
    while let Some(arg) = args.next() {
        let (key, inline) = match arg.to_str().and_then(|text| text.split_once('=')) {
            Some((key, value)) => (key.to_owned(), Some(OsString::from(value))),
            None => (arg.to_string_lossy().into_owned(), None),
        };
        if key == "--help" || key == "-h" {
            println!(
                "Usage: nntmux-cpu-snapshot --output PATH [--proc-root PATH]\nRetain raw CPU interval endpoints. Hidden cgroup ancestors remain unavailable."
            );
            return 0;
        }
        if key != "--output" && key != "--proc-root" {
            eprintln!(
                "CPU evidence argument error: unknown argument {}",
                arg.to_string_lossy()
            );
            return 2;
        }
        let is_inline = inline.is_some();
        let Some(value) = inline.or_else(|| args.next()) else {
            eprintln!("CPU evidence argument error: {key} requires a path");
            return 2;
        };
        if !is_inline && value.to_str().is_some_and(option_like) {
            eprintln!("CPU evidence argument error: {key} requires a path");
            return 2;
        }
        if key == "--output" {
            output = Some(PathBuf::from(value));
        } else {
            if value.to_str().is_none() {
                eprintln!("CPU evidence argument error: --proc-root requires a UTF-8 path");
                return 2;
            }
            proc = PathBuf::from(value);
        }
    }
    let Some(output) = output else {
        eprintln!("CPU evidence argument error: --output is required");
        return 2;
    };
    let data = nzbyenc::cpu_snapshot::snapshot(&proc);
    let written = (|| -> io::Result<()> {
        let file = OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(output)?;
        let mut file = BufWriter::new(file);
        serde_json::to_writer_pretty(&mut file, &data)?;
        file.write_all(b"\n")?;
        file.flush()
    })();
    if let Err(error) = written {
        eprintln!("CPU evidence output failed: {error}");
        return 2;
    }
    if data["os_cpu_usable"] != true {
        eprintln!("CPU evidence retained but no usable OS CPU counters");
        return 1;
    }
    0
}
// argparse accepts negative numeric-looking values as paths, while other
// separate option-looking tokens are flags. Inline values are always literal.
fn option_like(value: &str) -> bool {
    let Some(number) = value.strip_prefix('-') else {
        return false;
    };
    if number.is_empty() {
        return false;
    }
    let digits = |part: &str| part.bytes().all(|byte| byte.is_ascii_digit());
    let negative_number = match number.split_once('.') {
        Some((whole, fraction)) => digits(whole) && !fraction.is_empty() && digits(fraction),
        None => digits(number),
    };
    !negative_number
}

fn main() {
    std::process::exit(run(std::env::args_os().skip(1)));
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        fs,
        sync::atomic::{AtomicU64, Ordering},
    };
    static NEXT: AtomicU64 = AtomicU64::new(0);
    struct Fixture {
        root: PathBuf,
        proc: PathBuf,
        output: PathBuf,
    }
    impl Fixture {
        fn new() -> Self {
            let root = std::env::temp_dir().join(format!(
                "nntmux-cpu-cli-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            let proc = root.join("proc");
            fs::create_dir_all(&proc).unwrap();
            fs::write(proc.join("stat"), "cpu 1 2 3 4\n").unwrap();
            Self {
                output: root.join("out.json"),
                root,
                proc,
            }
        }
        fn args(&self) -> std::vec::IntoIter<OsString> {
            vec![
                "--output".into(),
                self.output.clone().into_os_string(),
                "--proc-root".into(),
                self.proc.clone().into_os_string(),
            ]
            .into_iter()
        }
        fn data(&self) -> serde_json::Value {
            serde_json::from_slice(&fs::read(&self.output).unwrap()).unwrap()
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.root).unwrap();
        }
    }
    #[test]
    fn writes_timestamped_exclusive_artifact_even_without_cgroup_evidence() {
        let f = Fixture::new();
        assert_eq!(run(f.args()), 0);
        let data = f.data();
        assert_eq!(data["schema_version"], 1);
        assert_eq!(data["os_cpu_usable"], true);
        assert_eq!(data["cgroup_v2"]["status"], "unavailable");
        assert!(data["captured_at"].as_str().unwrap().ends_with("+00:00"));
        assert!(data["monotonic_ns"].as_u64().unwrap() > 0);
        let original = fs::read(&f.output).unwrap();
        assert_eq!(original.last(), Some(&b'\n'));
        assert_eq!(run(f.args()), 2);
        assert_eq!(fs::read(&f.output).unwrap(), original);
    }
    #[test]
    fn unusable_os_counters_return_one_and_retain_artifact() {
        let f = Fixture::new();
        for raw in [
            Some("cpu no ticks here\n"),
            Some("cpu 1 2 3\n"),
            Some("cpu -1 2 3 4\n"),
            Some("intr 100\n"),
            None,
        ] {
            match raw {
                Some(raw) => fs::write(f.proc.join("stat"), raw).unwrap(),
                None => fs::remove_file(f.proc.join("stat")).unwrap(),
            };
            assert_eq!(run(f.args()), 1);
            assert_eq!(f.data()["os_cpu_usable"], false);
            fs::remove_file(&f.output).unwrap();
        }
    }
    #[test]
    fn help_succeeds_without_required_output_or_artifact() {
        let f = Fixture::new();
        assert_eq!(run([OsString::from("--help")].into_iter()), 0);
        assert!(!f.output.exists());
    }
    #[test]
    fn option_flags_are_not_consumed_as_separate_path_values() {
        let f = Fixture::new();
        for option in ["-h", "--unknown", "-x"] {
            let args = vec![
                OsString::from("--proc-root"),
                OsString::from(option),
                OsString::from("--output"),
                f.output.clone().into_os_string(),
            ];
            assert_eq!(run(args.into_iter()), 2);
            assert!(!f.output.exists());
        }
    }
    #[test]
    fn inline_option_looking_paths_and_negative_numeric_paths_are_literal() {
        let f = Fixture::new();
        for option in ["--proc-root=--literal", "--proc-root=-x"] {
            assert_eq!(
                run([
                    OsString::from(option),
                    OsString::from("--output"),
                    f.output.clone().into_os_string()
                ]
                .into_iter()),
                1
            );
            assert_eq!(
                f.data()["os"]["stat"]["path"],
                format!("{}/stat", option.split_once('=').unwrap().1)
            );
            fs::remove_file(&f.output).unwrap();
        }
        for value in ["-1", "-1.3", "-.3", "-"] {
            assert_eq!(
                run([
                    OsString::from("--proc-root"),
                    OsString::from(value),
                    OsString::from("--output"),
                    f.output.clone().into_os_string()
                ]
                .into_iter()),
                1
            );
            fs::remove_file(&f.output).unwrap();
        }
    }
    #[test]
    fn non_utf8_proc_root_is_an_argument_error_without_artifact() {
        use std::os::unix::ffi::OsStringExt;
        let f = Fixture::new();
        let args = [
            OsString::from("--proc-root"),
            OsString::from_vec(vec![b'/', 255]),
            OsString::from("--output"),
            f.output.clone().into_os_string(),
        ];
        assert_eq!(run(args.into_iter()), 2);
        assert!(!f.output.exists());
    }
    #[test]
    fn invalid_arguments_and_output_failures_return_two() {
        let mut f = Fixture::new();
        for args in [
            vec![],
            vec!["--output"],
            vec!["--unknown"],
            vec!["--output", "--proc-root"],
        ] {
            assert_eq!(run(args.into_iter().map(OsString::from)), 2);
            assert!(!f.output.exists());
        }
        f.output = f.root.join("absent/out.json");
        assert_eq!(run(f.args()), 2);
        assert!(!f.output.exists());
        f.output = f.proc.clone();
        assert_eq!(run(f.args()), 2);
        assert!(f.output.is_dir());
    }
}
