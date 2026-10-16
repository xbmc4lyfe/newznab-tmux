#[path = "../installation.rs"]
mod installation;
#[path = "../tooling.rs"]
#[allow(dead_code)]
mod tooling;

fn main() {
    match run() {
        Ok(report) => println!("{report}"),
        Err(error) => {
            eprintln!("installation failed: {error}");
            std::process::exit(1);
        }
    }
}

fn run() -> Result<serde_json::Value, String> {
    use std::collections::BTreeMap;
    use std::path::Path;
    use std::process::{Command, Stdio};
    use std::time::{Duration, Instant};
    let mut arguments = std::env::args().skip(1);
    let mut options = BTreeMap::new();
    while let Some(key) = arguments.next() {
        if ![
            "--library",
            "--destination",
            "--build-manifest",
            "--verify-staged",
        ]
        .contains(&key.as_str())
        {
            return Err(format!("unknown option {key}"));
        }
        let value = arguments.next().ok_or("option requires value")?;
        if options.insert(key, value).is_some() {
            return Err("duplicate option".into());
        }
    }
    if let Some(staged) = options.get("--verify-staged") {
        if options.len() != 1 {
            return Err("staged verifier accepts only its library path".into());
        }
        return tooling::verify_library(Path::new(staged), true);
    }
    let source = Path::new(options.get("--library").ok_or("missing --library")?);
    let destination = Path::new(
        options
            .get("--destination")
            .ok_or("missing --destination")?,
    );
    let prepare = || {
        if let Some(manifest) = options.get("--build-manifest") {
            let status = Command::new("cargo")
                .args([
                    "build",
                    "--release",
                    "--locked",
                    "--lib",
                    "--manifest-path",
                    manifest,
                ])
                .status()
                .map_err(|e| e.to_string())?;
            if !status.success() {
                return Err("locked Rust build failed; destination preserved".into());
            }
        }
        Ok(())
    };
    let verify = |staged: &Path| {
        let mut child = Command::new(std::env::current_exe().map_err(|e| e.to_string())?)
            .arg("--verify-staged")
            .arg(staged)
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|e| e.to_string())?;
        let deadline = Instant::now() + Duration::from_secs(30);
        loop {
            if child.try_wait().map_err(|e| e.to_string())?.is_some() {
                break;
            }
            if Instant::now() >= deadline {
                let _ = child.kill();
                let _ = child.wait();
                return Err("staged verification timed out; destination preserved".into());
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        let output = child.wait_with_output().map_err(|e| e.to_string())?;
        if !output.status.success() {
            return Err(format!(
                "staged verifier failed: {}",
                String::from_utf8_lossy(&output.stderr)
            ));
        }
        serde_json::from_slice(&output.stdout).map_err(|e| format!("invalid verifier receipt: {e}"))
    };
    if options.contains_key("--build-manifest") {
        installation::install_after(source, destination, prepare, verify)
    } else {
        installation::install(source, destination, verify)
    }
}
