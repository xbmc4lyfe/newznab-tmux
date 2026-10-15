#[path = "../abi_receipts.rs"]
mod abi_receipts;
#[path = "../benchmark.rs"]
mod benchmark;
#[path = "../tooling.rs"]
mod tooling;

use std::collections::BTreeMap;
use std::path::Path;

fn main() {
    match run() {
        Ok(report) => println!("{report}"),
        Err(error) => {
            eprintln!("qualification failed: {error}");
            std::process::exit(1);
        }
    }
}

fn run() -> Result<serde_json::Value, String> {
    let mut arguments = std::env::args().skip(1);
    let command = arguments
        .next()
        .ok_or("use freeze, check, verify or bench")?;
    let mut options = BTreeMap::new();
    while let Some(name) = arguments.next() {
        if !name.starts_with("--") {
            return Err("expected named option".into());
        }
        let value = arguments.next().ok_or("option needs value")?;
        if options.insert(name, value).is_some() {
            return Err("duplicate option".into());
        }
    }
    let path = |name: &str| -> Result<&Path, String> {
        options
            .get(name)
            .map(|s| Path::new(s.as_str()))
            .ok_or_else(|| format!("missing {name}"))
    };
    let allowed: Option<&[&str]> = match command.as_str() {
        "freeze" => Some(&["--reference", "--output"]),
        "check" => Some(&["--candidate", "--receipts"]),
        "verify" => Some(&["--library"]),
        "bench" | "bench-worker" | "capture-child" => None,
        _ => return Err("unknown qualification command".into()),
    };
    if let Some(allowed) = allowed
        && let Some(option) = options.keys().find(|key| !allowed.contains(&key.as_str()))
    {
        return Err(format!("unknown option {option}"));
    }
    match command.as_str() {
        "freeze" => abi_receipts::freeze(path("--reference")?, path("--output")?),
        "check" => abi_receipts::check(path("--candidate")?, path("--receipts")?),
        "verify" => tooling::verify_library(path("--library")?, true),
        "bench" => benchmark::run(&options),
        "bench-worker" => benchmark::worker(&options),
        "capture-child" => benchmark::capture_child(&options),
        _ => Err("unknown qualification command".into()),
    }
}
