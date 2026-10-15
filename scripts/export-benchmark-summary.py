#!/usr/bin/env python3
"""Export public benchmark metrics without copying private evidence payloads."""

import argparse
import hashlib
import itertools
import json
import math
import os
from pathlib import Path
import re
import statistics

UTILITY_BASELINES = {
    "fingerprint": ("a1a9b0829610c3b62e7d0bdb418669a89c36c90b", "2c24de1374bc2f734961d3a7159fb13eaca175839f565a14004bcd41d7f3a374"),
    "cpu-snapshot": ("5cc5303b502e653d83bba1951dec2f5d2da1db35", "44186d8c2cf2f72f63f41d46bab30e0d78b683a43441c802c8e62280fdf03812"),
}
UTILITY_KINDS = ("fingerprint-ffi", "fingerprint-worker", "cpu-snapshot")
INPUTS = {**{kind: f"utility-evidence/{kind}/report.json" for kind in UTILITY_KINDS},
    "cpp": "benchmark-evidence/paired/observations.jsonl",
    "php": "benchmark-evidence/php-paired/observations.jsonl",
    "profile": "processing-evidence/profile/observations.jsonl",
    "presentation": "benchmark-evidence/results.jsonl",
}
SIZES = (4096, 524288, 1048576, 8388608)
DENSITIES = ("ordinary", "heavy")
PATHS = ("tolerant", "strict", "body")
PHASES = ("full-analyzer", "framing", "headers", "body-metadata", "relationship", "raw-body", "whole-body")
BODY_STAGES = ("allocate_and_memcpy", "native_parse_and_serialize", "ffi_string_and_free", "json_decode")
SCENARIOS = ("clean", "wrapped", "escaped")
BATCHES = (1, 2, 5, 10, 16, 50, 100)
DECISIONS = ("invalid", "regression", "useful_improvement", "inconclusive")
SOURCE_PATHS = {
    "scripts/benchmark-yenc.php", "scripts/build-rapidyenc.sh", "composer.lock",
    "scripts/benchmark-article-processing.php", "scripts/benchmark-release-names.php",
    "app/Services/YencService.php", "app/Services/NNTP/NNTPService.php",
    "app/Services/Releases/Fingerprint/NativeMetadataParser.php",
    "app/Services/Releases/Fingerprint/NzbFingerprintAnalyzer.php",
    "app/Support/AdminReleaseNames.php", "app/Support/ReleaseNameDisplay.php",
    "tests/Fixtures/YencArticles.php", "tests/Support/YencNntpHarness.php",
    "docker/app/nzb-yenc-ffi/Cargo.toml", "docker/app/nzb-yenc-ffi/Cargo.lock",
    "docker/app/nzb-yenc-ffi/src/lib.rs", "docker/app/nzb-yenc-ffi/src/release_names.rs",
    "docker/app/php/release-presentation-preload.php", "vendor/composer/installed.json",
}


def number(value):
    try:
        return type(value) in (int, float) and math.isfinite(value)
    except OverflowError:
        return False


def reject_constant(value):
    raise ValueError("Non-finite JSON constant")


def finite_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise ValueError("Non-finite JSON number")
    return result


def profile_samples(result):
    observations = result.get("observations")
    if not isinstance(observations, list):
        return 0
    return sum(isinstance(sample, dict) and sample.get("warmup") is False
               and number(sample.get("elapsed_ms")) and sample["elapsed_ms"] >= 0
               for sample in observations)


def body_stage_medians(result):
    samples = result.get("body_ffi_breakdown")
    if not isinstance(samples, list):
        return {}
    timings = {stage: [] for stage in BODY_STAGES}
    for sample in samples:
        if not isinstance(sample, dict) or sample.get("warmup") is not False or sample.get("correct") is not True:
            continue
        stages = sample.get("stages_ms")
        if not isinstance(stages, dict):
            continue
        for stage in BODY_STAGES:
            if number(stages.get(stage)) and stages[stage] >= 0:
                timings[stage].append(stages[stage])
    medians = {}
    for stage, values in timings.items():
        if values:
            median = statistics.median(values)
            if not number(median):
                median = statistics.median([value / 2 for value in values]) * 2
            if number(median):
                medians[stage] = median
    return medians


def count(value):
    return type(value) is int and value >= 0


def digest(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


def identities(records):
    """Only fixed public path keys and validated digests may leave the evidence."""
    sources, libraries = {}, {}
    for record in records:
        for field in ("source_sha256", "sources"):
            mapping = record.get(field)
            if isinstance(mapping, dict):
                for key in SOURCE_PATHS:
                    if digest(mapping.get(key)):
                        sources[key] = mapping[key]
        if digest(record.get("library_sha256")):
            libraries["native"] = record["library_sha256"]
        mapping = record.get("libraries", {})
        if isinstance(mapping, dict):
            for backend in ("cpp", "rust"):
                entry = mapping.get(backend)
                if isinstance(entry, dict) and digest(entry.get("sha256")):
                    libraries[backend] = entry["sha256"]
        entry = record.get("library")
        if isinstance(entry, dict) and digest(entry.get("sha256")):
            libraries["native"] = entry["sha256"]
    return {"source_sha256": sources, "library_sha256": libraries}


def summarize(kind, records):
    rows, invalid = [], False
    if kind in ("cpp", "php"):
        expected = set(itertools.product(SIZES, DENSITIES, PATHS))
        for record in records:
            if record.get("type") != "stratum_summary":
                continue
            row = {key: record.get(key) for key in ("size", "density", "path")}
            decision = record.get("decision")
            row["decision"] = decision if decision in DECISIONS else "invalid"
            for key in ("eligible_pairs", "invalid_pairs", "bootstrap_replicates", "confidence_family_size"):
                if count(record.get(key)):
                    row[key] = record[key]
            ratio_key = "median_rust_over_" + kind
            if number(record.get(ratio_key)) and record[ratio_key] > 0:
                row[ratio_key] = record[ratio_key]
            interval = record.get("bootstrap_95_percent_interval")
            if isinstance(interval, list) and len(interval) == 2 and all(number(x) and x > 0 for x in interval) and interval[0] <= interval[1]:
                row["bootstrap_95_percent_interval"] = interval
            invalid |= row["decision"] == "invalid" or not count(record.get("eligible_pairs")) or not count(record.get("invalid_pairs"))
            invalid |= row.get("invalid_pairs", 1) != 0 or row.get("eligible_pairs", 0) < 3
            invalid |= ratio_key not in row or "bootstrap_95_percent_interval" not in row
            rows.append(row)
        keys = [(row["size"], row["density"], row["path"]) for row in rows]
        terminal = records[-1] if records and records[-1].get("type") == "run_summary" else {}
        status = terminal.get("status") if terminal.get("status") in DECISIONS else None
        invalid |= bool(terminal) and (status is None or terminal.get("strata") != len(expected))
        invalid |= any(record.get("type") == "run_failure" for record in records)
    elif kind == "profile":
        expected = set(itertools.product(SIZES[:3], DENSITIES, PHASES))
        for record in records:
            if record.get("type") != "observation":
                continue
            result = record.get("result")
            result = result if isinstance(result, dict) else {}
            row = {key: record.get(key) for key in ("size", "density", "phase")}
            row["eligible"] = record.get("eligible") is True
            row["status"] = "ok" if record.get("status") == "ok" and result.get("status") == "ok" else "invalid"
            row["samples"] = profile_samples(result)
            if row["phase"] == "body-metadata":
                row["body_ffi_median_ms"] = body_stage_medians(result)
            for key in ("median_ms", "min_ms", "max_ms"):
                if number(result.get(key)) and result[key] >= 0:
                    row[key] = result[key]
            invalid |= not row["eligible"] or row["status"] != "ok" or "median_ms" not in row
            rows.append(row)
        keys = [(row["size"], row["density"], row["phase"]) for row in rows]
        terminal = records[-1] if records and records[-1].get("type") == "summary" else {}
        status = "ok" if terminal.get("status") == "ok" else "invalid" if terminal else None
        invalid |= bool(terminal) and (terminal.get("invalid_observations") != 0 or terminal.get("source_and_library_identities_stable") is not True or terminal.get("instrumentation_identities_stable") is not True)
        invalid |= any(record.get("type") == "failure" for record in records)
    else:
        expected = set(itertools.product(SCENARIOS, BATCHES))
        for record in records:
            row = {key: record.get(key) for key in ("scenario", "rows")}
            small = type(record.get("rows")) is int and record["rows"] < 16
            row["candidate_backend"] = "php-small-batch" if small else "rust"
            if small:
                row["correctness_passed"] = record.get("correctness_passed") is True
                row["performance_gate"] = "not_applicable_same_php_path"
                invalid |= not row["correctness_passed"] or record.get("performance_gate") != row["performance_gate"] or record.get("candidate_backend") != row["candidate_backend"]
            else:
                row["gate_passed"] = record.get("gate_passed") is True
                for key in ("samples", "iterations", "php_median_ns", "rust_median_ns", "improvement_percent"):
                    if number(record.get(key)):
                        row[key] = record[key]
                invalid |= record.get("candidate_backend") != "rust" or type(record.get("gate_passed")) is not bool
                invalid |= not count(record.get("samples")) or record.get("samples", 0) < 11
                invalid |= any(key not in row for key in ("php_median_ns", "rust_median_ns", "improvement_percent"))
                invalid |= any(row.get(key, 0) <= 0 for key in ("php_median_ns", "rust_median_ns"))
            rows.append(row)
        keys = [(row["scenario"], row["rows"]) for row in rows]
        terminal = {}
        status = "passed" if all(row.get("gate_passed", True) for row in rows) else "gate_failed"
    # Unknown fixture labels must never be reflected into the public document.
    safe_rows, seen = [], set()
    for key, row in zip(keys, rows):
        try:
            valid_key = key in expected
        except TypeError:
            valid_key = False
        if not valid_key or key in seen:
            invalid = True
            continue
        seen.add(key)
        safe_rows.append(row)
    complete = seen == expected and (kind == "presentation" or bool(terminal))
    status = "invalid" if invalid else "partial" if not complete else status
    flags = {key: terminal[key] for key in ("source_and_library_identities_stable", "instrumentation_identities_stable") if type(terminal.get(key)) is bool}
    return safe_rows, status, {"expected_rows": len(expected), "observed_rows": len(seen), "complete": complete}, flags


def rust_payload_mode(kind, records, status):
    """Use one successful protocol's recorded build manifest, never comparison kind."""
    if kind not in ("cpp", "php", "profile") or status not in ("ok", "useful_improvement", "inconclusive", "regression"):
        return "unknown"
    protocols = [record for record in records if record.get("type") == "protocol"]
    if len(protocols) != 1:
        return "unknown"
    protocol = protocols[0]
    if kind == "profile":
        manifest = protocol.get("runtime_manifest")
    else:
        manifest = protocol
        for key in ("runtime", "manifest", "contents"):
            manifest = manifest.get(key) if isinstance(manifest, dict) else None
    mode = manifest.get("rust_payload_mode") if isinstance(manifest, dict) else None
    return mode if mode in ("incumbent", "experimental") else "unknown"


def utility_summary(kind, report):
    """Publish fixed fixture names and numeric metrics; never paths, commands or raw observations."""
    cpu = kind == "cpu-snapshot"
    revision, baseline_hash = UTILITY_BASELINES["cpu-snapshot" if cpu else "fingerprint"]
    expected = ("common-mounted", "rare-deep64", "rare-unavailable") if cpu else ("ids16", "ids4096", "ids10000-unique-plus-duplicates", "ids50000", "xml10000")
    protocol = report.get("protocol", {})
    if cpu and isinstance(protocol, dict) and protocol.get("host_proc_absent_at_start") is True:
        expected = (*expected, "rare-host-unavailable")
    source = report.get("sources", {})
    measured = report.get("summary", {})
    if not isinstance(protocol, dict) or not isinstance(source, dict) or not isinstance(measured, dict):
        raise ValueError("Invalid utility report")
    baseline = source.get("baseline", {})
    baseline = baseline if isinstance(baseline, dict) else {}
    invalid = baseline.get("sha256") != baseline_hash
    invalid |= report.get("benchmark") != ("cpu-snapshot-cold-cli" if cpu else "nzb-article-fingerprint-paired-comparison")
    version = protocol.get("version")
    invalid |= (type(version) is not int or version not in (2, 3)) if cpu else version != 3
    family_size = len(expected) + int("rare-host-unavailable" in expected)
    confidence_valid = cpu and version == 3
    if confidence_valid:
        confidence_valid = (protocol.get("bootstrap_rng") == "splitmix64-rejection"
                            and protocol.get("order_rng") == "splitmix64-rejection"
                            and protocol.get("bootstrap_resamples") == 4000
                            and protocol.get("confidence_method") == "paired_bootstrap_bonferroni"
                            and type(protocol.get("confidence_family_size")) is int
                            and protocol["confidence_family_size"] == family_size
                            and type(protocol.get("bootstrap_seed")) is int
                            and 0 <= protocol["bootstrap_seed"] < 2 ** 64)
        if "rare-host-unavailable" in expected:
            confidence_valid &= (protocol.get("host_absent_median_ratio_ci_lower_gate") == 2.0
                                 and protocol.get("host_absent_p99_ratio_ci_lower_gate") == 1.5)
        invalid |= not confidence_valid
    invalid |= bool(set(measured) - set(expected))
    if cpu:
        invalid |= report.get("correctness_pass") is not True or report.get("changed_artifacts") != []
        invalid |= type(report.get("adoption_gate_pass")) is not bool
    else:
        invalid |= report.get("invalid_observations") != [] or protocol.get("backend") != kind.removeprefix("fingerprint-")
    rows = []
    for fixture in expected:
        if fixture not in measured:
            continue
        metrics = measured[fixture]
        if not isinstance(metrics, dict):
            invalid = True
            continue
        samples = metrics.get("valid_pairs" if cpu else "pairs_valid")
        required = metrics.get("required_pairs" if cpu else "pairs_total")
        declared = protocol.get("pairs" if cpu else "pairs_per_fixture")
        valid = count(samples) and count(required) and samples > 0 and samples == required == declared
        row = dict(fixture=fixture, candidate_backend="rust-cli" if cpu else kind.removeprefix("fingerprint-"),
                   samples=samples if count(samples) else 0, invalid_pairs=max(0, required - samples) if count(required) and count(samples) else 0,
                   p99_sample_sufficient=cpu and count(samples) and samples >= 1000)
        scalar_keys = ("baseline_median_ns", "candidate_median_ns", "median_ratio", "paired_median_ratio", "baseline_p99_ns", "candidate_p99_ns", "p99_ratio") if cpu else ("median_baseline_over_candidate_ratio", "median_cold_baseline_over_candidate_ratio")
        for key in scalar_keys:
            if number(metrics.get(key)) and metrics[key] > 0:
                row[key] = metrics[key]
            else:
                valid = False
        if cpu:
            interval_keys = ("median_ratio_ci95", "p99_ratio_ci95")
            if version == 3:
                interval_keys += ("gate_speedup_simultaneous_ci95",)
                if fixture == "rare-host-unavailable":
                    interval_keys += ("host_median_speedup_simultaneous_ci95",)
                statistic = "median_ratio" if fixture == "common-mounted" else "p99_ratio"
                valid &= metrics.get("gate_statistic") == statistic
                row["gate_statistic"] = statistic
            for key in interval_keys:
                interval = metrics.get(key)
                if isinstance(interval, list) and len(interval) == 2 and all(number(x) and x > 0 for x in interval) and interval[0] <= interval[1]:
                    row[key] = interval
                else:
                    valid = False
            valid &= type(metrics.get("gate_pass")) is bool
            valid &= metrics.get("p99_sample_sufficient") is row["p99_sample_sufficient"]
            row["gate_passed"] = metrics.get("gate_pass") is True
        else:
            pairs = metrics.get("pairs")
            valid &= isinstance(pairs, list) and len(pairs) == samples and all(isinstance(pair, dict) and pair.get("eligible") is True for pair in pairs)
        row["eligible"] = bool(valid)
        invalid |= not valid
        rows.append(row)
    identities = {}
    allowed = ("baseline", "candidate", "composer_lock", "benchmark", "rust_cargo_lock", "rust_accumulator", "rust_abi", "rust_worker", "php_worker", "native_library", "native_worker", "candidate_binary", "coordinator_binary", "cargo_lock", "cargo_toml", "candidate_source", "candidate_cli", "coordinator_source", "python_executable", "crate_lib_source")
    for name in allowed:
        entry = source.get(name)
        if isinstance(entry, dict) and digest(entry.get("sha256")):
            identities[name] = entry["sha256"]
    complete = len(rows) == len(expected)
    result = dict(rows=rows, status="invalid" if invalid else "partial" if not complete else "measured_gate_failed" if cpu and not report["adoption_gate_pass"] else "measured",
                completeness=dict(expected_rows=len(expected), observed_rows=len(rows), complete=complete),
                baseline_revision=revision, source_sha256=identities,
                protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                evidence_type="synthetic_cli_comparison" if cpu else "paired_public_operation_median_screen")
    if cpu:
        result["measurement_protocol_version"] = version if type(version) is int and version in (2, 3) else 0
        result["confidence_status"] = ("historical_unqualified" if version == 2 else
                                       "simultaneous_gate_intervals" if confidence_valid and not invalid else "unqualified")
        if confidence_valid:
            result["confidence_family_size"] = family_size
    return result


def env_count(key):
    value = os.environ.get(key, "")
    return int(value) if re.fullmatch(r"[0-9]{1,20}", value) else 0


def export(kind, runner):
    sha = os.environ.get("GITHUB_SHA", "")
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    summary = {"schema_version": 1, "kind": kind, "runner": runner,
               "head_sha": sha if re.fullmatch(r"[0-9a-f]{40,64}", sha) else "",
               "run_id": env_count("GITHUB_RUN_ID"), "run_attempt": env_count("GITHUB_RUN_ATTEMPT"),
               "repository": repository if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) else "",
               "status": "missing", "rows": [], "evidence_type": "diagnostic_only" if kind == "profile" else "presentation_median_screen" if kind == "presentation" else "paired_median_screen",
               "adoption_qualified": False, "rust_payload_mode": "unknown"}
    if kind in UTILITY_KINDS:
        try:
            report_raw = Path(INPUTS[kind]).read_bytes()
            report = json.loads(report_raw, parse_constant=reject_constant, parse_float=finite_float)
            if not isinstance(report, dict):
                raise ValueError("Expected report object")
            summary.update(utility_summary(kind, report))
            summary["report_sha256"] = hashlib.sha256(report_raw).hexdigest()
            runtime_file = Path("utility-evidence/runtime.json")
            if not runtime_file.is_file():
                summary["status"] = "partial" if summary["status"] != "invalid" else "invalid"
            else:
                runtime = json.loads(runtime_file.read_text(), parse_constant=reject_constant, parse_float=finite_float)
                allowed_runtime = ("executed_sha", "rustc", "cargo", "php", "python", "architecture", "cpu_model", "runner_image_os", "runner_image_version", "build_profile")
                if not isinstance(runtime, dict) or runtime.get("executed_sha") != summary["head_sha"]:
                    raise ValueError("Executed build identity mismatch")
                summary["runtime_identity"] = {key: runtime[key] for key in allowed_runtime if isinstance(runtime.get(key), str) and len(runtime[key]) <= 4096}
                if count(runtime.get("logical_cpus")):
                    summary["runtime_identity"]["logical_cpus"] = runtime["logical_cpus"]
        except FileNotFoundError:
            pass
        except (OSError, UnicodeError, ValueError, TypeError, OverflowError):
            summary["status"] = "invalid"
        if kind == "cpu-snapshot" and summary.get("confidence_status") == "simultaneous_gate_intervals":
            if summary["status"] not in ("measured", "measured_gate_failed") or "runtime_identity" not in summary:
                summary["confidence_status"] = "unqualified"
        return summary
    records, malformed = [], False
    try:
        with Path(INPUTS[kind]).open(encoding="utf-8") as source:
            for line in source:
                try:
                    record = json.loads(line, parse_constant=reject_constant, parse_float=finite_float)
                    if not isinstance(record, dict):
                        malformed = True
                    else:
                        records.append(record)
                except (ValueError, TypeError):
                    malformed = True
    except FileNotFoundError:
        return summary
    except (OSError, UnicodeError):
        malformed = True
    rows, status, completeness, flags = summarize(kind, records)
    summary.update(rows=rows, status="invalid" if malformed else status, completeness=completeness, **flags, **identities(records))
    summary["rust_payload_mode"] = rust_payload_mode(kind, records, summary["status"])
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--kind", choices=INPUTS, required=True)
    parser.add_argument("--runner", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9_. -]{1,80}", args.runner):
        parser.error("runner must be a short public label")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(export(args.kind, args.runner), indent=2, allow_nan=False) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
