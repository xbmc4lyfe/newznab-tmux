use serde_json::{Value, json};
use std::os::unix::fs::{FileTypeExt, MetadataExt};
use std::{
    env,
    fs::{self, File, OpenOptions},
    io::{self, Write},
    path::Path,
};
type Result<T> = std::result::Result<T, Box<dyn std::error::Error>>;
fn required<'a>(v: &'a Value, k: &str) -> Result<&'a Value> {
    v.get(k).ok_or_else(|| format!("missing key: {k}").into())
}
fn py(v: &Value) -> String {
    match v {
        Value::Null => "None".into(),
        Value::Bool(v) => if *v { "True" } else { "False" }.into(),
        Value::String(s) => s.clone(),
        Value::Array(a) => format!(
            "[{}]",
            a.iter()
                .map(|v| if let Value::String(s) = v {
                    format!("'{s}'")
                } else {
                    py(v)
                })
                .collect::<Vec<_>>()
                .join(", ")
        ),
        Value::Number(n) => {
            if n.is_f64() {
                float_py(n.as_f64().unwrap())
            } else {
                n.to_string()
            }
        }
        _ => v.to_string(),
    }
}
fn float_py(n: f64) -> String {
    let raw = n.to_string();
    if n != 0.0 && (n.abs() < 1e-4 || n.abs() >= 1e16) {
        let sci = format!("{n:e}");
        let (a, b) = sci.split_once('e').unwrap();
        let exp: i32 = b.parse().unwrap();
        format!(
            "{a}e{}{exp:02}",
            if exp < 0 { "-" } else { "+" },
            exp = exp.abs()
        )
    } else if !raw.contains('.') {
        format!("{raw}.0")
    } else {
        raw
    }
}
// Read raw median/interval tokens after serde validates the whole row. The default
// serde float parser can round one ULP differently from Python; Rust str::parse
// preserves the producer token and float_py supplies Python display conventions.
fn value_end(s: &str, start: usize) -> usize {
    let b = s.as_bytes();
    let mut i = start;
    let mut depth = 0;
    let mut quoted = false;
    let mut escape = false;
    while i < b.len() {
        let c = b[i];
        if quoted {
            if escape {
                escape = false
            } else if c == b'\\' {
                escape = true
            } else if c == b'"' {
                quoted = false
            }
        } else {
            match c {
                b'"' => quoted = true,
                b'[' | b'{' => depth += 1,
                b']' | b'}' => {
                    if depth == 0 {
                        return i;
                    }
                    depth -= 1
                }
                b',' if depth == 0 => return i,
                _ => {}
            }
        }
        i += 1;
    }
    i
}
fn raw_field<'a>(s: &'a str, wanted: &str) -> Option<&'a str> {
    let b = s.as_bytes();
    let mut i = s.find('{')? + 1;
    let mut found = None;
    loop {
        while i < b.len() && (b[i].is_ascii_whitespace() || b[i] == b',') {
            i += 1
        }
        if i >= b.len() || b[i] == b'}' {
            return found;
        }
        let start = i;
        i += 1;
        let mut escape = false;
        while i < b.len() {
            if !escape && b[i] == b'"' {
                break;
            }
            escape = !escape && b[i] == b'\\';
            i += 1
        }
        let key: String = serde_json::from_str(&s[start..=i]).ok()?;
        i += 1;
        while i < b.len() && (b[i].is_ascii_whitespace() || b[i] == b':') {
            i += 1
        }
        let start = i;
        i = value_end(s, start);
        if key == wanted {
            found = Some(s[start..i].trim());
        }
    }
}
fn lexical_py(s: &str) -> Result<String> {
    let s = s.trim();
    if s.starts_with('[') {
        let mut out = Vec::new();
        let mut i = 1;
        while i < s.len() - 1 {
            while i < s.len() - 1 && s.as_bytes()[i].is_ascii_whitespace() {
                i += 1
            }
            if i == s.len() - 1 {
                break;
            }
            let end = value_end(s, i);
            out.push(lexical_py(&s[i..end])?);
            i = end;
            if s.as_bytes().get(i) == Some(&b',') {
                i += 1
            }
        }
        return Ok(format!("[{}]", out.join(", ")));
    }
    if s.starts_with(|c: char| c == '-' || c.is_ascii_digit())
        && (s.contains('.') || s.contains('e') || s.contains('E'))
    {
        return Ok(float_py(s.parse()?));
    }
    Ok(py(&serde_json::from_str(s)?))
}
fn copy_content(src: &Path, dst: &Path) -> Result<()> {
    let sm = fs::metadata(src)?;
    if sm.file_type().is_fifo() {
        return Err("source is a named pipe".into());
    }
    if let Ok(dm) = fs::metadata(dst) {
        if sm.dev() == dm.dev() && sm.ino() == dm.ino() {
            return Err("source and destination are the same file".into());
        }
        if dm.file_type().is_fifo() {
            return Err("destination is a named pipe".into());
        }
    }
    let mut from = File::open(src)?;
    let mut to = File::create(dst)?;
    io::copy(&mut from, &mut to)?;
    Ok(())
}
fn qualify(root: &Path) -> Result<()> {
    let dir = root.join("benchmark-evidence/build");
    let manifest_text = fs::read_to_string(dir.join("runtime.json"))?;
    let m: Value = serde_json::from_str(&manifest_text)?;
    let arch = required(&m, "runner_arch")?;
    if arch.is_array() || arch.is_object() {
        return Err("runner_arch is unhashable".into());
    }
    let mut kernel = required(&m, "rust_payload_kernel")?.clone();
    let original_id = required(&kernel, "id")?;
    let observed_number = if original_id.is_number() {
        let raw = raw_field(&manifest_text, "rust_payload_kernel")
            .and_then(|raw| raw_field(raw, "id"))
            .ok_or("missing kernel id token")?;
        let number = raw.parse::<f64>()?;
        if original_id.is_f64() {
            kernel["id"] =
                Value::Number(serde_json::Number::from_f64(number).ok_or("nonfinite kernel id")?);
        }
        Some(number)
    } else {
        None
    };
    let observed = required(&kernel, "id")?;
    let expected = match arch.as_str() {
        Some("X64") => Some(3),
        Some("ARM64") => Some(4),
        _ => None,
    };
    let qualified = expected.is_some_and(|n| observed_number == Some(n as f64));
    copy_content(
        &root.join("storage/app/rapidyenc/librapidyenc.so"),
        &dir.join("cpp.so"),
    )?;
    copy_content(
        &root.join("docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so"),
        &dir.join("rust.so"),
    )?;
    let q = json!({"status":if qualified{"qualified"}else{"unsupported_hardware"},"runner_arch":arch,"required_kernel_id":expected,"observed_kernel":&kernel,"git_head":required(&m,"git_head")?,"rust_library_sha256":required(&m,"rust_library_sha256")?,"cpp_library_sha256":required(&m,"cpp_library_sha256")?,"basis":"Same runtime selector used by the tested decoder; x86 requires VBMI2, ARM requires NEON.","conclusion":if qualified{"Hardware qualified for the paired comparison."}else{"Required SIMD kernel was not exercised; no eligible performance comparison."}});
    fs::write(
        dir.join("kernel-qualification.json"),
        serde_json::to_string_pretty(&q)? + "\n",
    )?;
    if !qualified {
        return Err(format!(
            "Unsupported hardware: required kernel {}, observed {}; evidence retained.",
            expected
                .map(|n| n.to_string())
                .unwrap_or_else(|| "None".into()),
            py(observed)
        )
        .into());
    }
    Ok(())
}
fn splitlines(s: &str) -> Vec<&str> {
    let mut rows = Vec::new();
    let mut start = 0;
    let mut chars = s.char_indices().peekable();
    while let Some((i, c)) = chars.next() {
        if matches!(
            c,
            '\n' | '\r'
                | '\u{000b}'
                | '\u{000c}'
                | '\u{001c}'
                | '\u{001d}'
                | '\u{001e}'
                | '\u{0085}'
                | '\u{2028}'
                | '\u{2029}'
        ) {
            rows.push(&s[start..i]);
            start = i + c.len_utf8();
            if c == '\r' && chars.peek().is_some_and(|(_, c)| *c == '\n') {
                let (j, c) = chars.next().unwrap();
                start = j + c.len_utf8();
            }
        }
    }
    if start < s.len() {
        rows.push(&s[start..])
    }
    rows
}
fn summary(root: &Path, cpp: bool, output: &Path) -> Result<()> {
    let p = if cpp {
        "benchmark-evidence/paired/observations.jsonl"
    } else {
        "benchmark-evidence/php-paired/observations.jsonl"
    };
    let p = root.join(p);
    let mut rows = Vec::new();
    let mut raw_rows = Vec::new();
    if p.exists() {
        let text = fs::read_to_string(&p)?;
        for line in splitlines(&text) {
            rows.push(serde_json::from_str::<Value>(line)?);
            raw_rows.push(line.to_owned());
        }
    }
    let mut out = OpenOptions::new().append(true).create(true).open(output)?;
    if cpp {
        write!(out, "## Native yEnc paired fixture comparison\n\n")?;
        let qp = root.join("benchmark-evidence/build/kernel-qualification.json");
        if qp.exists() {
            let q: Value = serde_json::from_str(&fs::read_to_string(&qp)?)?;
            writeln!(
                out,
                "Kernel qualification: `{}`. {}\n",
                required(&q, "status")?
                    .as_str()
                    .ok_or("status not string")?,
                required(&q, "conclusion")?
                    .as_str()
                    .ok_or("conclusion not string")?
            )?;
        }
        write!(
            out,
            "Scope: identical synthetic fixtures through the same PHP decoder service and memory-backed NNTP BODY harness. No archive-metadata or production-capacity claim.\n\n| Bytes | Density | Path | Decision | Rust / C++ median | 95% interval | Eligible / invalid pairs |\n|---:|---|---|---|---:|---|---|\n"
        )?;
    } else {
        write!(
            out,
            "## Rust versus PHP fallback\n\nSame PHP service, exact byte/CRC correctness, randomized fresh-process pairs. Includes FFI overhead. Synthetic decoder fixtures only; no production throughput claim.\n\n| Bytes | Density | Path | Decision | Rust / PHP | 95% interval | Eligible / invalid |\n|---:|---|---|---|---:|---|---|\n"
        )?;
    }
    for (row, raw) in rows.iter().zip(&raw_rows) {
        if !row.is_object() {
            return Err("row is not object".into());
        }
        if row.get("type").and_then(Value::as_str) != Some("stratum_summary") {
            continue;
        }
        let fields = ["size", "density", "path", "decision"];
        let mut vals = Vec::new();
        for k in fields {
            vals.push(py(required(row, k)?))
        }
        let ratio = if cpp {
            "median_rust_over_cpp"
        } else {
            "median_rust_over_php"
        };
        vals.push(match raw_field(raw, ratio) {
            Some(s) => lexical_py(s)?,
            None => "unavailable".into(),
        });
        vals.push(match raw_field(raw, "bootstrap_95_percent_interval") {
            Some(s) => lexical_py(s)?,
            None => "unavailable".into(),
        });
        let eligible = py(required(row, "eligible_pairs")?);
        let invalid = py(required(row, "invalid_pairs")?);
        writeln!(out, "| {} | {eligible} / {invalid} |", vals.join(" | "))?;
    }
    let final_row = rows.iter().rev().find(|r| {
        matches!(
            r.get("type").and_then(Value::as_str),
            Some("run_summary" | "run_failure")
        )
    });
    let status = if let Some(row) = final_row {
        required(row, "status")?
            .as_str()
            .ok_or("status not string")?
    } else {
        "setup incomplete; no performance conclusion"
    };
    if cpp {
        write!(
            out,
            "\nRun result: `{status}`.\n\nIntervals use a simultaneous 95% bootstrap rule across the predeclared fixture groups. Inconclusive strata do not demonstrate a Rust speed advantage. Failed or timed-out pairs are retained and make the affected stratum invalid.\n"
        )?;
    } else {
        write!(
            out,
            "\nResult: `{status}`. Inconclusive results do not qualify a Rust replacement. Failures are retained and invalidate their stratum.\n"
        )?;
    }
    Ok(())
}
fn presentation_header(root: &Path) -> Result<()> {
    let header = fs::read_to_string(root.join("docker/app/php/release-presentation.h"))?
        .replace("\r\n", "\n")
        .replace('\r', "\n");
    let mut output = File::create(root.join("benchmark-evidence/presentation.h"))?;
    let needle = "/usr/local/lib/nntmux/libnzbyenc.so";
    let rendered = if header.contains(needle) {
        let library = root.join("docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so");
        header.replace(needle, library.to_str().ok_or("library path is not UTF-8")?)
    } else {
        header
    };
    output.write_all(rendered.as_bytes())?;
    Ok(())
}
fn run() -> Result<()> {
    let args: Vec<_> = env::args().collect();
    if args.len() != 3 || args[1] != "--mode" {
        return Err("usage: nntmux-evidence-utility --mode qualify|cpp-summary|php-summary|presentation-header".into());
    }
    match args[2].as_str() {
        "presentation-header" => presentation_header(&env::current_dir()?),
        "qualify" => qualify(Path::new(".")),
        "cpp-summary" => summary(
            Path::new("."),
            true,
            Path::new(&env::var("GITHUB_STEP_SUMMARY")?),
        ),
        "php-summary" => summary(
            Path::new("."),
            false,
            Path::new(&env::var("GITHUB_STEP_SUMMARY")?),
        ),
        _ => Err("unknown mode".into()),
    }
}
fn main() {
    if let Err(e) = run() {
        eprintln!("{e}");
        std::process::exit(1)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicU64, Ordering};
    static NEXT: AtomicU64 = AtomicU64::new(0);
    struct Fixture(std::path::PathBuf);
    impl Fixture {
        fn new() -> Self {
            let root = env::temp_dir().join(format!(
                "nntmux-evidence-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir_all(root.join("benchmark-evidence/build")).unwrap();
            Self(root)
        }
        fn write(&self, path: &str, content: &str) {
            let path = self.0.join(path);
            fs::create_dir_all(path.parent().unwrap()).unwrap();
            fs::write(path, content).unwrap();
        }
        fn read(&self, path: &str) -> String {
            fs::read_to_string(self.0.join(path)).unwrap()
        }
        fn manifest(&self, arch: Value, id: Value) {
            self.write("benchmark-evidence/build/runtime.json", &json!({"runner_arch":arch,"rust_payload_kernel":{"id":id,"name":"kernel"},"git_head":"head","rust_library_sha256":"rust-hash","cpp_library_sha256":"cpp-hash"}).to_string());
            self.write("storage/app/rapidyenc/librapidyenc.so", "cpp-library");
            self.write(
                "docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so",
                "rust-library",
            );
        }
        fn observations(&self, cpp: bool, data: &str) {
            self.write(
                if cpp {
                    "benchmark-evidence/paired/observations.jsonl"
                } else {
                    "benchmark-evidence/php-paired/observations.jsonl"
                },
                data,
            );
        }
        fn summarize(&self, cpp: bool) -> Result<()> {
            summary(&self.0, cpp, &self.0.join("summary"))
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    const ROW: &str = r#"{"type":"stratum_summary","size":1024,"density":"dense","path":"decode","decision":"inconclusive","eligible_pairs":11,"invalid_pairs":0,"median_rust_over_cpp":0.9999999999999999,"median_rust_over_php":1.0,"bootstrap_95_percent_interval":[0.00001,1e20]}"#;

    #[test]
    fn presentation_header_rewrites_all_paths_and_normalizes_newlines() {
        let f = Fixture::new();
        f.write("docker/app/php/release-presentation.h", "#define LIB \"/usr/local/lib/nntmux/libnzbyenc.so\"\r\n// /usr/local/lib/nntmux/libnzbyenc.so\r// café\n");
        presentation_header(&f.0).unwrap();
        let library =
            f.0.join("docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so");
        assert_eq!(
            f.read("benchmark-evidence/presentation.h"),
            format!(
                "#define LIB \"{}\"\n// {}\n// café\n",
                library.display(),
                library.display()
            )
        );
    }

    #[test]
    fn presentation_header_preserves_unmatched_content_and_existing_permissions() {
        use std::os::unix::fs::PermissionsExt;
        let f = Fixture::new();
        f.write(
            "docker/app/php/release-presentation.h",
            "\u{feff}// unchanged\r\n",
        );
        f.write("benchmark-evidence/presentation.h", "old");
        let output = f.0.join("benchmark-evidence/presentation.h");
        fs::set_permissions(&output, fs::Permissions::from_mode(0o640)).unwrap();
        presentation_header(&f.0).unwrap();
        assert_eq!(
            f.read("benchmark-evidence/presentation.h"),
            "\u{feff}// unchanged\n"
        );
        assert_eq!(
            fs::metadata(output).unwrap().permissions().mode() & 0o777,
            0o640
        );
    }

    #[test]
    fn presentation_header_input_failure_does_not_truncate_output() {
        let f = Fixture::new();
        f.write("benchmark-evidence/presentation.h", "keep");
        assert!(presentation_header(&f.0).is_err());
        f.write("docker/app/php/release-presentation.h", "placeholder");
        fs::write(f.0.join("docker/app/php/release-presentation.h"), [255]).unwrap();
        assert!(presentation_header(&f.0).is_err());
        assert_eq!(f.read("benchmark-evidence/presentation.h"), "keep");
    }

    #[test]
    fn presentation_header_does_not_create_a_missing_output_parent() {
        let f = Fixture::new();
        f.write("docker/app/php/release-presentation.h", "header");
        fs::remove_dir_all(f.0.join("benchmark-evidence")).unwrap();
        assert!(presentation_header(&f.0).is_err());
        assert!(!f.0.join("benchmark-evidence").exists());
    }

    #[test]
    fn qualified_architectures_copy_libraries_and_record_identity() {
        for (arch, id) in [("X64", json!(3)), ("ARM64", json!(4)), ("X64", json!(3.0))] {
            let f = Fixture::new();
            f.manifest(json!(arch), id.clone());
            qualify(&f.0).unwrap();
            let text = f.read("benchmark-evidence/build/kernel-qualification.json");
            assert!(text.ends_with('\n'));
            let q: Value = serde_json::from_str(&text).unwrap();
            assert_eq!(q["status"], "qualified");
            assert_eq!(q["runner_arch"], arch);
            assert_eq!(q["observed_kernel"]["id"], id);
            assert_eq!(q["git_head"], "head");
            assert_eq!(q["rust_library_sha256"], "rust-hash");
            assert_eq!(q["cpp_library_sha256"], "cpp-hash");
            assert_eq!(f.read("benchmark-evidence/build/cpp.so"), "cpp-library");
            assert_eq!(f.read("benchmark-evidence/build/rust.so"), "rust-library");
        }
    }
    #[test]
    fn unsupported_hardware_retains_artifacts_and_exact_failure_prefix() {
        for (arch, id, expected, observed) in [
            ("X64", json!(0), "3", "0"),
            ("other", json!(4), "None", "4"),
            ("X64", Value::Null, "3", "None"),
            ("X64", json!(true), "3", "True"),
            ("X64", json!("3"), "3", "3"),
        ] {
            let f = Fixture::new();
            f.manifest(json!(arch), id);
            let error = qualify(&f.0).unwrap_err().to_string();
            assert_eq!(
                error,
                format!(
                    "Unsupported hardware: required kernel {expected}, observed {observed}; evidence retained."
                )
            );
            let q: Value =
                serde_json::from_str(&f.read("benchmark-evidence/build/kernel-qualification.json"))
                    .unwrap();
            assert_eq!(q["status"], "unsupported_hardware");
            assert!(f.0.join("benchmark-evidence/build/rust.so").exists());
        }
    }
    #[test]
    fn unhashable_architecture_fails_before_any_library_copy() {
        let f = Fixture::new();
        f.manifest(json!([]), json!(3));
        assert!(qualify(&f.0).is_err());
        assert!(!f.0.join("benchmark-evidence/build/cpp.so").exists());
    }
    #[test]
    fn neighboring_float_kernel_ids_never_qualify() {
        for (arch, id) in [
            ("X64", "2.9999999999999996"),
            ("X64", "3.0000000000000004"),
            ("ARM64", "3.9999999999999996"),
        ] {
            let f = Fixture::new();
            f.manifest(json!(arch), json!(3));
            let manifest = f
                .read("benchmark-evidence/build/runtime.json")
                .replace("\"id\":3", &format!("\"id\":{id}"));
            f.write("benchmark-evidence/build/runtime.json", &manifest);
            assert!(qualify(&f.0).is_err(), "neighbor {id} must not qualify");
            let q: Value =
                serde_json::from_str(&f.read("benchmark-evidence/build/kernel-qualification.json"))
                    .unwrap();
            assert_eq!(q["status"], "unsupported_hardware");
        }
    }
    #[test]
    fn qualifier_failure_keeps_only_prior_copy_effects() {
        let f = Fixture::new();
        f.manifest(json!("X64"), json!(3));
        fs::remove_file(f.0.join("docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so")).unwrap();
        assert!(qualify(&f.0).is_err());
        assert_eq!(f.read("benchmark-evidence/build/cpp.so"), "cpp-library");
        assert!(
            !f.0.join("benchmark-evidence/build/kernel-qualification.json")
                .exists()
        );
        f.write("benchmark-evidence/build/runtime.json", "{}");
        assert!(
            qualify(&f.0)
                .unwrap_err()
                .to_string()
                .contains("runner_arch")
        );
    }
    #[test]
    fn copy_retains_destination_mode_and_rejects_aliases() {
        use std::os::unix::fs::PermissionsExt;
        let f = Fixture::new();
        f.write("src", "new");
        f.write("dst", "old");
        let src = f.0.join("src");
        let dst = f.0.join("dst");
        fs::set_permissions(&dst, fs::Permissions::from_mode(0o640)).unwrap();
        copy_content(&src, &dst).unwrap();
        assert_eq!(
            fs::metadata(&dst).unwrap().permissions().mode() & 0o777,
            0o640
        );
        assert_eq!(f.read("dst"), "new");
        assert!(
            copy_content(&src, &src)
                .unwrap_err()
                .to_string()
                .contains("same file")
        );
        fs::hard_link(&src, f.0.join("alias")).unwrap();
        assert!(copy_content(&src, &f.0.join("alias")).is_err());
        std::os::unix::fs::symlink(&src, f.0.join("symlink")).unwrap();
        assert!(copy_content(&src, &f.0.join("symlink")).is_err());
    }
    #[test]
    fn fifo_source_and_destination_fail_without_blocking() {
        let f = Fixture::new();
        f.write("src", "data");
        let fifo = f.0.join("fifo");
        assert!(
            std::process::Command::new("mkfifo")
                .arg(&fifo)
                .status()
                .unwrap()
                .success()
        );
        assert!(
            copy_content(&fifo, &f.0.join("new"))
                .unwrap_err()
                .to_string()
                .contains("named pipe")
        );
        assert!(
            copy_content(&f.0.join("src"), &fifo)
                .unwrap_err()
                .to_string()
                .contains("named pipe")
        );
        assert!(!f.0.join("new").exists());
    }
    #[test]
    fn summaries_append_rows_in_order_and_choose_last_result() {
        for cpp in [true, false] {
            let f = Fixture::new();
            f.write("summary", "prior\n");
            f.observations(cpp,&format!("{{\"type\":\"run_summary\",\"status\":\"old\"}}\n{ROW}\n{{\"type\":\"run_failure\",\"status\":\"failed\"}}\n"));
            f.summarize(cpp).unwrap();
            let result = f.read("summary");
            assert!(result.starts_with("prior\n## "));
            assert!(result.contains("| 1024 | dense | decode | inconclusive |"));
            assert!(result.contains("[1e-05, 1e+20] | 11 / 0 |"));
            assert!(result.contains("`failed`"));
            assert!(!result.contains("`old`"));
            assert!(result.contains(if cpp { "0.9999999999999999" } else { "1.0" }));
            assert!(result.contains(if cpp {
                "Intervals use a simultaneous 95%"
            } else {
                "Inconclusive results do not qualify"
            }));
        }
    }
    #[test]
    fn empty_or_missing_observations_render_no_conclusion() {
        for cpp in [true, false] {
            for present in [true, false] {
                let f = Fixture::new();
                if present {
                    f.observations(cpp, "")
                }
                f.summarize(cpp).unwrap();
                let text = f.read("summary");
                assert!(text.contains("setup incomplete; no performance conclusion"));
                assert!(text.contains("|---:"));
            }
        }
    }
    #[test]
    fn malformed_json_aborts_before_summary_is_opened() {
        for cpp in [true, false] {
            let f = Fixture::new();
            f.write("summary", "unchanged");
            f.observations(cpp, &format!("{ROW}\n{{broken"));
            assert!(f.summarize(cpp).is_err());
            assert_eq!(f.read("summary"), "unchanged");
        }
    }
    #[test]
    fn qualification_is_read_after_heading_append() {
        let f = Fixture::new();
        f.write("benchmark-evidence/build/kernel-qualification.json", "{bad");
        assert!(f.summarize(true).is_err());
        assert_eq!(
            f.read("summary"),
            "## Native yEnc paired fixture comparison\n\n"
        );
        f.write(
            "benchmark-evidence/build/kernel-qualification.json",
            r#"{"status":"qualified","conclusion":"Evidence."}"#,
        );
        f.summarize(true).unwrap();
        assert!(
            f.read("summary")
                .contains("Kernel qualification: `qualified`. Evidence.\n\n")
        );
    }
    #[test]
    fn missing_required_row_fields_leave_heading_without_partial_row() {
        let f = Fixture::new();
        f.observations(false, r#"{"type":"stratum_summary"}"#);
        assert!(f.summarize(false).is_err());
        let text = f.read("summary");
        assert!(text.ends_with("|---:|---|---|---|---:|---|---|\n"));
    }
    #[test]
    fn absent_and_null_optional_values_are_distinct() {
        let mut row: Value = serde_json::from_str(ROW).unwrap();
        row.as_object_mut().unwrap().remove("median_rust_over_cpp");
        row["bootstrap_95_percent_interval"] = Value::Null;
        let f = Fixture::new();
        f.observations(true, &row.to_string());
        f.summarize(true).unwrap();
        assert!(f.read("summary").contains("| unavailable | None |"));
    }
    #[test]
    fn python_splitlines_handles_all_unicode_boundaries() {
        assert_eq!(splitlines(""), Vec::<&str>::new());
        assert_eq!(
            splitlines("a\r\nb\rc\nd\u{b}e\u{c}f\u{1c}g\u{1d}h\u{1e}i\u{85}j\u{2028}k\u{2029}"),
            vec!["a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k"]
        );
        assert_eq!(splitlines("a\n\nb"), vec!["a", "", "b"]);
    }
    #[test]
    fn lexical_numbers_preserve_python_float_representation() {
        for (raw, expected) in [
            ("1.0", "1.0"),
            ("-0.0", "-0.0"),
            ("1e20", "1e+20"),
            ("1e-7", "1e-07"),
            ("0.9999999999999999", "0.9999999999999999"),
            ("[0.00001, 1.0, null, true]", "[1e-05, 1.0, None, True]"),
            ("[]", "[]"),
            ("7", "7"),
        ] {
            assert_eq!(lexical_py(raw).unwrap(), expected);
        }
        assert_eq!(py(&json!(["x", false, 0.5])), "['x', False, 0.5]");
        assert_eq!(py(&json!({"a":1})), "{\"a\":1}");
    }
    #[test]
    fn duplicate_numeric_keys_use_last_value_even_when_escaped() {
        let row = r#"{"median_rust_over_cpp":0.5,"nested":{"median_rust_over_cpp":1.0},"median_rust_over_c\u0070p":0.9999999999999999}"#;
        assert_eq!(
            raw_field(row, "median_rust_over_cpp"),
            Some("0.9999999999999999")
        );
    }
}
