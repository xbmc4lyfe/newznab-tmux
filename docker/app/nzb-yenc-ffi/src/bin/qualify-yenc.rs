#[path = "../abi_receipts.rs"]
mod abi_receipts;
#[path = "../benchmark.rs"]
mod benchmark;
#[path = "../tooling.rs"]
mod tooling;

use std::collections::BTreeMap;
use std::path::Path;

fn main() {
    match run(std::env::args().skip(1)) {
        Ok(report) => println!("{report}"),
        Err(error) => {
            eprintln!("qualification failed: {error}");
            std::process::exit(1);
        }
    }
}

fn finish_report(command: &str, report: serde_json::Value) -> Result<serde_json::Value, String> {
    match command {
        "freeze" | "check" | "verify" | "bench" => tooling::with_adoption_eligibility(report),
        "check-worker" | "bench-worker" | "capture-child" => Ok(report),
        _ => Err("unknown qualification command".into()),
    }
}

fn run(mut arguments: impl Iterator<Item = String>) -> Result<serde_json::Value, String> {
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
        "check" | "check-worker" => Some(&["--candidate", "--receipts"]),
        "verify" => Some(&["--library"]),
        "bench" | "bench-worker" | "capture-child" => None,
        _ => return Err("unknown qualification command".into()),
    };
    if let Some(allowed) = allowed
        && let Some(option) = options.keys().find(|key| !allowed.contains(&key.as_str()))
    {
        return Err(format!("unknown option {option}"));
    }
    let report = match command.as_str() {
        "freeze" => abi_receipts::freeze(path("--reference")?, path("--output")?),
        "check" | "check-worker" => abi_receipts::check(path("--candidate")?, path("--receipts")?),
        "verify" => tooling::verify_library(path("--library")?, true),
        "bench" => benchmark::run(&options),
        "bench-worker" => benchmark::worker(&options),
        "capture-child" => benchmark::capture_child(&options),
        _ => Err("unknown qualification command".into()),
    }?;
    finish_report(&command, report)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn public_diagnostic_reports_deny_adoption_without_changing_historical_results() {
        for command in ["freeze", "check", "verify", "bench"] {
            let historical = json!({"status":"passed","protocol":"historical",
                "receipt":{"status":"ok","calls":9479481}});
            let mut report = finish_report(command, historical.clone()).unwrap();
            assert_eq!(report["adoption_eligibility"]["eligible"], false);
            report
                .as_object_mut()
                .unwrap()
                .remove("adoption_eligibility");
            assert_eq!(report, historical, "{command}");
        }
    }

    #[test]
    fn internal_worker_reports_keep_exact_historical_json_shape() {
        for command in ["check-worker", "bench-worker", "capture-child"] {
            let historical = json!({"status":"ok","artifact":{"sha256":"candidate"},
                "receipt":{"count":3,"crc":42}});
            let original = serde_json::to_vec(&historical).unwrap();
            let report = finish_report(command, historical).unwrap();
            assert_eq!(serde_json::to_vec(&report).unwrap(), original, "{command}");
            assert!(report.get("adoption_eligibility").is_none());
        }
    }

    #[test]
    fn replay_worker_and_public_check_share_strict_named_options() {
        for command in ["check", "check-worker"] {
            let arguments = [
                command,
                "--candidate",
                "unused",
                "--receipts",
                "unused",
                "--extra",
                "value",
            ];
            assert_eq!(
                run(arguments.into_iter().map(str::to_owned)).unwrap_err(),
                "unknown option --extra"
            );
        }
    }
}
