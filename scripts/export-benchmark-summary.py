#!/usr/bin/env python3
"""Export public benchmark metrics without copying private evidence payloads."""

import argparse
import hashlib
import importlib.util
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
RUST_SOURCE_PATHS = {"docker/app/nzb-yenc-ffi/src/" + name + ".rs" for name in (
    "abi_crc", "abi_decoder", "abi_decoder_arm", "abi_decoder_x86", "abi_receipts",
    "article_dates", "article_fingerprint", "article_identity", "article_xref", "benchmark", "cpu_snapshot", "formats",
    "header_filename_identity", "header_filename_unicode", "iso_path_tables", "iso_primary_copies", "lib", "nntp_body", "nntp_headers", "par2", "par2_source_ranges", "payload_decoder",
    "payload_decoder_arm", "payload_decoder_x86", "rar5_copy_checksums", "rar5_quick_open", "release_names",
    "sample_plan", "seven_zip_ranges", "xz_first_block", "yenc_framing", "zstd_frame",
    "tooling", "evidence", "installation",
    "bin/benchmark-article-fingerprint", "bin/benchmark-cpu-snapshot", "bin/install-yenc",
    "bin/nntmux-article-fingerprint", "bin/nntmux-cpu-snapshot", "bin/nntmux-evidence-utility",
    "bin/qualify-yenc", "bin/verify-yenc-evidence")}
SOURCE_PATHS |= RUST_SOURCE_PATHS | {"app/Services/Yenc/" + name + ".php" for name in (
    "ArticleFrame", "DecoderFactory", "NativePayloadDecoder", "PayloadDecoder", "PhpPayloadDecoder", "RawPayloadDecoder", "YencPart")}
KERNEL_NAMES = {0: "scalar", 1: "ssse3", 2: "avx2", 3: "avx512-vbmi2", 4: "neon"}
CURRENT_STATUSES = {"ok", "useful_improvement", "inconclusive", "regression", "passed", "gate_failed"}


def canonical_digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False, allow_nan=False).encode()).hexdigest()


def frozen_json(path):
    with Path(path).open("rb") as source:
        raw = source.read(8 * 1024 * 1024 + 1)
    if len(raw) > 8 * 1024 * 1024:
        raise ValueError("Oversized frozen receipt")
    value = json.loads(raw, parse_constant=reject_constant, parse_float=finite_float)
    if not isinstance(value, dict):
        raise ValueError("Invalid frozen receipt")
    return value, hashlib.sha256(raw).hexdigest()


def kernel_identity(value):
    if isinstance(value, dict):
        identifier = value.get("id")
        if type(identifier) is not int or identifier not in KERNEL_NAMES or value.get("name") != KERNEL_NAMES[identifier]:
            raise ValueError("Invalid kernel receipt")
    else:
        identifier = value
        if type(identifier) is not int or identifier not in KERNEL_NAMES:
            raise ValueError("Invalid kernel identity")
    return {"id": identifier, "name": KERNEL_NAMES[identifier]}


def build_receipt(kind, summary):
    prefix = "processing-evidence" if kind == "profile" else "benchmark-evidence"
    manifest, receipt_hash = frozen_json(prefix + "/build/runtime.json")
    if (manifest.get("git_head") != summary["head_sha"]
            or str(manifest.get("github_run_id")) != str(summary["run_id"])):
        raise ValueError("Runtime receipt does not match selected source and run")
    candidate = manifest.get("candidate_git_head", manifest["git_head"]) if kind == "profile" else manifest["git_head"]
    if not isinstance(candidate, str) or not re.fullmatch("[0-9a-f]{40}", candidate):
        raise ValueError("Invalid selected candidate source")
    experiment = candidate != summary["head_sha"]
    rust_sources = manifest.get("rust_source_sha256")
    if (not isinstance(rust_sources, dict) or set(rust_sources) != RUST_SOURCE_PATHS
            or any(not digest(value) for value in rust_sources.values())):
        raise ValueError("Incomplete frozen Rust implementation inventory")
    sources = rust_sources
    library = manifest.get("rust_library_sha256")
    if kind == "profile" and "candidate_git_head" in manifest:
        selected = manifest.get("candidate_source_sha256")
        if not isinstance(selected, dict) or not selected:
            raise ValueError("Missing selected candidate graph")
        sources = {key: value for key, value in selected.items() if key in SOURCE_PATHS}
        if (not sources or any(not digest(value) for value in sources.values())
                or not experiment and not RUST_SOURCE_PATHS <= set(sources)):
            raise ValueError("Incomplete selected candidate graph")
        library = manifest.get("candidate_library_sha256")
    if not digest(library):
        raise ValueError("Missing selected native library identity")
    result = {"provenance_status": "verified_experiment" if experiment else "verified_current",
              "runtime_manifest_sha256": receipt_hash, "source_sha256": sources,
              "runtime_identity": {"executed_sha": manifest["git_head"]}, "hardware_qualification": "unavailable"}
    if experiment:
        result["candidate_head_sha"] = candidate
    for field in ("rustc", "cargo", "php", "runner_image_os", "runner_image_version"):
        value = manifest.get(field)
        if isinstance(value, str) and len(value) <= 4096:
            result["runtime_identity"][field] = value
    if manifest.get("runner_arch") in ("X64", "ARM64"):
        result["runtime_identity"]["architecture"] = manifest["runner_arch"]
    kernel = (manifest.get("candidate_payload_kernel_id") if kind == "profile" and "candidate_git_head" in manifest
              else manifest.get("rust_payload_kernel", manifest.get("rust_payload_kernel_id")))
    if kernel is not None:
        result["rust_payload_kernel"] = kernel_identity(kernel)
        result["hardware_qualification"] = "observed_only"
    return manifest, result, library


def cpp_hardware_receipt(manifest, evidence, library):
    qualification, receipt_hash = frozen_json("benchmark-evidence/build/kernel-qualification.json")
    required = {"X64": 3, "ARM64": 4}.get(manifest.get("runner_arch"))
    if required is None or type(qualification.get("required_kernel_id")) is not int or qualification["required_kernel_id"] != required:
        raise ValueError("Invalid designated kernel requirement")
    observed = kernel_identity(qualification.get("observed_kernel"))
    if observed != evidence.get("rust_payload_kernel"):
        raise ValueError("Kernel observation disagrees with runtime")
    for field in ("runner_arch", "git_head", "rust_library_sha256", "cpp_library_sha256"):
        if qualification.get(field) != manifest.get(field):
            raise ValueError("Kernel qualification does not match build receipt")
    if not digest(manifest.get("cpp_library_sha256")) or library != manifest.get("rust_library_sha256"):
        raise ValueError("Invalid qualified library identities")
    supported = observed["id"] == required
    if qualification.get("status") != ("qualified" if supported else "unsupported_hardware"):
        raise ValueError("Kernel status disagrees with designated requirement")
    return {"hardware_qualification": "designated_kernel" if supported else "unsupported_hardware",
            "required_payload_kernel": kernel_identity(required), "kernel_qualification_sha256": receipt_hash}


def measurement_provenance(kind, summary, records):
    """Bind the public measurements to the same frozen execution receipt."""
    manifest, evidence, library = build_receipt(kind, summary)
    if kind == "presentation":
        protocol = manifest.get("presentation_protocol")
        if (not isinstance(protocol, dict) or set(protocol) != {"samples", "iterations", "order_seed"}
                or any(type(value) is not int or value < 1 for value in protocol.values())):
            raise ValueError("Invalid presentation protocol receipt")
        for record in records:
            if record.get("library_sha256") != library:
                raise ValueError("Presentation library disagrees with build receipt")
            if type(record.get("rows")) is not int or record["rows"] not in BATCHES:
                raise ValueError("Unknown presentation batch")
            if record.get("candidate_backend") == "rust" and (record.get("samples") != protocol["samples"]
                    or record.get("order_seed") != protocol["order_seed"]
                    or record.get("iterations") != max(protocol["iterations"], 10000 // record["rows"])):
                raise ValueError("Presentation observations disagree with protocol")
    else:
        protocols = [row for row in records if row.get("type") == "protocol"]
        if len(protocols) != 1:
            raise ValueError("Expected one frozen measurement protocol")
        protocol = protocols[0]
        embedded = protocol.get("runtime_manifest") if kind == "profile" else protocol.get("runtime", {}).get("manifest", {}).get("contents")
        if embedded != manifest:
            raise ValueError("Measurement and build runtime receipts disagree")
        protocol_library = (protocol.get("library", {}).get("sha256") if kind == "profile"
                            else protocol.get("libraries", {}).get("rust", {}).get("sha256"))
        if protocol_library != library:
            raise ValueError("Measured library does not match selected source receipt")
        if kind == "cpp":
            if protocol.get("libraries", {}).get("cpp", {}).get("sha256") != manifest.get("cpp_library_sha256"):
                raise ValueError("C++ baseline library disagrees with receipt")
            evidence.update(cpp_hardware_receipt(manifest, evidence, library))
            if evidence["hardware_qualification"] != "designated_kernel":
                raise ValueError("Measured C++ comparison lacks designated kernel")
    for record in records:
        for field in ("source_sha256", "sources"):
            sources = record.get(field, {})
            if isinstance(sources, dict) and any(key in sources and sources[key] != value for key, value in evidence["source_sha256"].items()):
                raise ValueError("Measured source graph disagrees with receipt")
    evidence["protocol_sha256"] = canonical_digest(protocol)
    return evidence


def unavailable_cpp_hardware(summary):
    """A failed strict SIMD gate is a hardware cause only with its matching receipt."""
    if not Path("benchmark-evidence/build/kernel-qualification.json").is_file():
        return summary
    try:
        manifest, evidence, library = build_receipt("cpp", summary)
        evidence.update(cpp_hardware_receipt(manifest, evidence, library))
        if evidence["hardware_qualification"] != "unsupported_hardware":
            return summary
        for backend in ("rust", "cpp"):
            with Path("benchmark-evidence/build/" + backend + ".so").open("rb") as binary:
                if hashlib.file_digest(binary, "sha256").hexdigest() != manifest[backend + "_library_sha256"]:
                    raise ValueError("Frozen qualification binary disagrees with receipt")
        summary.update(evidence, status="unsupported_hardware", rows=[], library_sha256={"rust": library, "cpp": manifest["cpp_library_sha256"]})
    except (OSError, UnicodeError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
        summary.update(status="invalid", provenance_status="invalid", hardware_qualification="unavailable")
    return summary


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
    if kind.startswith("fingerprint-") and isinstance(report.get("protocol"), dict) and report["protocol"].get("version") == 4:
        return full_fingerprint_summary(kind, report)
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
                            and type(protocol.get("bootstrap_resamples")) is int
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
    host_pairs = protocol.get("host_pairs_opt_in", 0) if cpu else 0
    host = report.get("host_comparison", {}) if cpu else {}
    host_requested = count(host_pairs) and host_pairs > 0
    host_confidence_valid = False
    if cpu:
        invalid |= not count(host_pairs) or host_pairs > 5000
    if host_requested:
        expected = (*expected, "host-live")
        if not isinstance(host, dict):
            raise ValueError("Invalid host comparison")
        host_summary = host.get("summary")
        if isinstance(host_summary, dict):
            measured = dict(measured, **{"host-live": host_summary})
        invalid |= (host.get("enabled") is not True or host.get("correctness_pass") is not True
                    or host.get("source_integrity_pass") is not True or report.get("overall_adoption_qualified") is not False)
        host_protocol = host.get("protocol", {})
        if not isinstance(host_protocol, dict):
            raise ValueError("Invalid host protocol")
        invalid |= (host_protocol.get("exact_default_cli") is not True
                    or not count(host_protocol.get("pairs")) or host_protocol.get("pairs") != host_pairs)
        if version == 3:
            host_confidence_valid = (type(host_protocol.get("version")) is int and host_protocol["version"] == 3
                                     and host_protocol.get("bootstrap_rng") == "splitmix64-rejection"
                                     and host_protocol.get("order_rng") == "splitmix64-rejection"
                                     and type(host_protocol.get("bootstrap_resamples")) is int
                                     and host_protocol["bootstrap_resamples"] == 4000
                                     and host_protocol.get("confidence_method") == "paired_bootstrap_bonferroni"
                                     and type(host_protocol.get("confidence_family_size")) is int
                                     and host_protocol["confidence_family_size"] == 2
                                     and host_protocol.get("confidence_level") == 0.95
                                     and type(host_protocol.get("bootstrap_seed")) is int
                                     and 0 <= host_protocol["bootstrap_seed"] < 2 ** 64
                                     and host_protocol.get("median_ratio_ci_lower_gate") == 1.5
                                     and host_protocol.get("p99_ratio_ci_lower_gate") == 1.5)
            invalid |= not host_confidence_valid
        observations = host.get("observations")
        if not isinstance(observations, list):
            invalid = True
        else:
            seen = set()
            for observation in observations:
                if not isinstance(observation, dict):
                    invalid = True
                    continue
                pair, backend = observation.get("pair"), observation.get("implementation")
                if not count(pair) or pair > host_pairs or backend not in ("baseline", "candidate"):
                    invalid = True
                    continue
                key = (pair, backend)
                invalid |= key in seen or observation.get("valid") is not True or observation.get("warmup") is not (pair == 0)
                seen.add(key)
                invalid |= not number(observation.get('elapsed_ns')) or observation.get('elapsed_ns', 0) <= 0
                validation = observation.get('validation')
                invalid |= not isinstance(validation, dict) or validation.get('valid') is not True
            invalid |= seen != set(itertools.product(range(host_pairs + 1), ("baseline", "candidate")))
    invalid |= bool(set(measured) - set(expected))
    if cpu:
        invalid |= report.get("correctness_pass") is not True or report.get("changed_artifacts") != []
        invalid |= type(report.get("adoption_gate_pass")) is not bool
    else:
        invalid |= report.get("invalid_observations") != [] or protocol.get("backend") != kind.removeprefix("fingerprint-")
        if "candidate_status" in report:
            invalid |= report["candidate_status"] != "experimental_withdrawn_from_production"
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
        declared = host_pairs if fixture == "host-live" else protocol.get("pairs" if cpu else "pairs_per_fixture")
        valid = count(samples) and count(required) and samples > 0 and samples == required == declared
        row = dict(fixture=fixture, candidate_backend="rust-cli" if cpu else kind.removeprefix("fingerprint-"),
                   samples=samples if count(samples) else 0, invalid_pairs=max(0, required - samples) if count(required) and count(samples) else 0,
                   p99_sample_sufficient=cpu and count(samples) and samples >= 1000)
        if cpu:
            row["comparison"] = "exact_host_cli" if fixture == "host-live" else "synthetic_cli"
        scalar_keys = ("baseline_median_ns", "candidate_median_ns", "median_ratio", "paired_median_ratio", "baseline_p99_ns", "candidate_p99_ns", "p99_ratio") if cpu else ("median_baseline_over_candidate_ratio", "median_cold_baseline_over_candidate_ratio")
        for key in scalar_keys:
            if number(metrics.get(key)) and metrics[key] > 0:
                row[key] = metrics[key]
            else:
                valid = False
        if cpu:
            interval_keys = ("median_ratio_ci95", "p99_ratio_ci95")
            if version == 3:
                if fixture == "host-live":
                    interval_keys += ("live_median_speedup_simultaneous_ci95", "live_p99_speedup_simultaneous_ci95")
                else:
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
            sufficient = (count(declared) and declared >= 1000 and row["p99_sample_sufficient"]
                          and metrics.get("p99_sample_sufficient") is True)
            if fixture == "host-live":
                host_confidence_valid &= sufficient
            else:
                confidence_valid &= sufficient
            row["gate_passed"] = metrics.get("gate_pass") is True
            if fixture == "host-live":
                valid &= host.get("host_gate_pass") is metrics.get("gate_pass") and report.get("host_gate_pass") is metrics.get("gate_pass")
                host_observations = host.get('observations') if isinstance(host.get('observations'), list) else []
                values = {backend: [row.get('elapsed_ns') for row in host_observations
                                    if isinstance(row, dict) and row.get('implementation') == backend and row.get('warmup') is False]
                          for backend in ('baseline', 'candidate')}
                valid &= not invalid and all(len(value) == samples and all(number(item) and item > 0 for item in value) for value in values.values())
                if valid:
                    b, c = values['baseline'], values['candidate']
                    rank = math.ceil(0.99 * samples) - 1
                    expected_values = (statistics.median(b), statistics.median(c), statistics.median(b) / statistics.median(c),
                                       statistics.median([left / right for left, right in zip(b, c)]),
                                       sorted(b)[rank], sorted(c)[rank], sorted(b)[rank] / sorted(c)[rank])
                    valid &= all(math.isclose(metrics[key], value, rel_tol=1e-12) for key, value in zip(scalar_keys, expected_values))
                    median_interval, p99_interval = (("live_median_speedup_simultaneous_ci95", "live_p99_speedup_simultaneous_ci95")
                                                     if version == 3 else ("median_ratio_ci95", "p99_ratio_ci95"))
                    expected_gate = (samples >= 1000 and metrics[median_interval][0] >= 1.5 and metrics[p99_interval][0] >= 1.5)
                    valid &= metrics['gate_pass'] is expected_gate
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
    result = dict(rows=rows, status="invalid" if invalid else "partial" if not complete else "measured_gate_failed" if cpu and (not report["adoption_gate_pass"] or host_requested and not report.get("host_gate_pass")) else "measured",
                completeness=dict(expected_rows=len(expected), observed_rows=len(rows), complete=complete),
                baseline_revision=revision, source_sha256=identities,
                protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                uncertainty_method=("paired_bootstrap_bonferroni" if version == 3 else "mixed_output_bootstrap" if protocol.get("bootstrap_rng") == "LCG64 state with SplitMix64 output mixing and unbiased rejection sampling" else "legacy_stratified_bootstrap_unqualified") if cpu else "not_reported",
                evidence_type="synthetic_and_exact_host_cli_comparison" if cpu and host_requested else "synthetic_cli_comparison" if cpu else "paired_public_operation_median_screen")
    if not cpu and report.get("candidate_status") == "experimental_withdrawn_from_production":
        result["candidate_status"] = "experimental_withdrawn_from_production"
    if cpu:
        result["measurement_protocol_version"] = version if type(version) is int and version in (2, 3) else 0
        result["confidence_status"] = ("historical_unqualified" if version == 2 else
                                       "simultaneous_gate_intervals" if confidence_valid and not invalid else "unqualified")
        if confidence_valid:
            result["confidence_family_size"] = family_size
        if host_requested:
            result["host_confidence_status"] = ("historical_unqualified" if version == 2 else
                                                "simultaneous_gate_intervals" if host_confidence_valid and not invalid else "unqualified")
            if host_confidence_valid:
                result["host_confidence_family_size"] = 2
    return result


FINGERPRINT_FIXTURES = ("ids16", "ids4096", "ids10000-unique-plus-duplicates", "ids50000", "xml10000",
                        "xml10000-fromXml", "incremental16", "ids16-max255", "incremental25000-max255-spill")
FINGERPRINT_UNCOMMON = {"xml10000-fromXml", "ids16-max255", "incremental25000-max255-spill"}


def positive_interval(value):
    return (isinstance(value, list) and len(value) == 2 and all(number(item) and item > 0 for item in value)
            and value[0] <= value[1])


def full_fingerprint_summary(kind, report):
    protocol, source, measured = (report.get(key, {}) for key in ("protocol", "sources", "summary"))
    if not all(isinstance(value, dict) for value in (protocol, source, measured)):
        raise ValueError("Invalid full fingerprint report")
    revision, baseline = UTILITY_BASELINES["fingerprint"]
    entry = source.get("baseline", {})
    invalid = (not isinstance(entry, dict) or entry.get("sha256") != baseline
               or report.get("benchmark") != "nzb-article-fingerprint-paired-comparison"
               or report.get("invalid_observations") != [] or report.get("adoption_qualified") is not False
               or protocol.get("coverage") != "full" or protocol.get("backend") != kind.removeprefix("fingerprint-")
               or bool(set(measured) - set(FINGERPRINT_FIXTURES)))
    if "candidate_status" in report:
        invalid |= report["candidate_status"] != "experimental_withdrawn_from_production"
    ordinary, tail = protocol.get("pairs_per_fixture"), protocol.get("tail_pairs_per_uncommon_fixture")
    invalid |= not count(ordinary) or not 1 <= ordinary <= 5000
    invalid |= tail is not None and (not count(tail) or not 1000 <= tail <= 5000)
    rows = []
    for name in FINGERPRINT_FIXTURES:
        summary = measured.get(name)
        if summary is None:
            continue
        if not isinstance(summary, dict):
            invalid = True
            continue
        expected = tail if name in FINGERPRINT_UNCOMMON and tail is not None else ordinary
        pairs = summary.get("pairs")
        valid = (count(expected) and summary.get("pairs_total") == expected and summary.get("pairs_valid") == expected
                 and isinstance(pairs, list) and len(pairs) == expected
                 and all(isinstance(pair, dict) and pair.get("eligible") is True for pair in pairs))
        if valid:
            valid &= {pair.get("pair") for pair in pairs if count(pair.get("pair"))} == set(range(1, expected + 1))
        for phase, prefix, metric_key in (("cold", "cold_", "cold_metrics"), ("repeated", "", "repeated_metrics")):
            metrics = summary.get(metric_key)
            row = dict(fixture=name, phase=phase, candidate_backend=kind.removeprefix("fingerprint-"),
                       samples=summary.get("pairs_valid") if count(summary.get("pairs_valid")) else 0,
                       p99_sample_sufficient=count(expected) and expected >= 1000,
                       comparison="exact_public_fingerprint_operation")
            good = valid and isinstance(metrics, dict) and metrics.get("eligible") is True
            if isinstance(metrics, dict):
                for key in ("baseline_median_ns", "candidate_median_ns", "paired_median_ratio"):
                    if number(metrics.get(key)) and metrics[key] > 0:
                        row[key] = metrics[key]
                    else:
                        good = False
                interval = metrics.get("median_ratio_ci95")
                sufficient = count(expected) and expected >= 11
                good &= metrics.get("median_sample_sufficient") is sufficient
                if sufficient and positive_interval(interval):
                    row["median_ratio_ci95"] = interval
                elif sufficient or interval is not None:
                    good = False
                else:
                    row["median_ratio_ci95"] = None
                decision = metrics.get("median_decision")
                if decision in ("useful_improvement", "regression", "inconclusive", "invalid"):
                    row["decision"] = decision
                else:
                    good = False
                expected_decision = ("useful_improvement" if positive_interval(interval) and interval[0] >= 1.5 else
                                     "regression" if positive_interval(interval) and interval[1] < 1 else "inconclusive")
                good &= decision == expected_decision
                if positive_interval(interval) and number(metrics.get("paired_median_ratio")):
                    good &= interval[0] <= metrics["paired_median_ratio"] <= interval[1]
                good &= metrics.get("p99_sample_sufficient") is row["p99_sample_sufficient"]
                if row["p99_sample_sufficient"]:
                    for key in ("baseline_p99_ns", "candidate_p99_ns", "p99_ratio"):
                        if number(metrics.get(key)) and metrics[key] > 0:
                            row[key] = metrics[key]
                        else:
                            good = False
                    interval = metrics.get("p99_ratio_ci95")
                    if positive_interval(interval):
                        row["p99_ratio_ci95"] = interval
                        row["p99_threshold_met"] = interval[0] >= 1.5
                        good &= metrics.get("p99_threshold_met") is row["p99_threshold_met"]
                    else:
                        good = False
                else:
                    row.update(p99_ratio=None, p99_ratio_ci95=None, p99_threshold_met=None)
                    good &= all(metrics.get(key) is None for key in ("baseline_p99_ns", "candidate_p99_ns", "p99_ratio", "p99_ratio_ci95", "p99_threshold_met"))
            if valid and good:
                baseline_values = [pair.get(prefix + "baseline_ns") for pair in pairs]
                candidate_values = [pair.get(prefix + "candidate_ns") for pair in pairs]
                good &= all(number(value) and value > 0 for value in baseline_values + candidate_values)
                if good:
                    expected_values = (statistics.median(baseline_values), statistics.median(candidate_values),
                                       statistics.median([b / c for b, c in zip(baseline_values, candidate_values)]))
                    good &= all(math.isclose(row[key], value, rel_tol=1e-12) for key, value in zip(
                        ("baseline_median_ns", "candidate_median_ns", "paired_median_ratio"), expected_values))
                    if row["p99_sample_sufficient"]:
                        rank = math.ceil(0.99 * expected) - 1
                        b, c = sorted(baseline_values)[rank], sorted(candidate_values)[rank]
                        good &= all(math.isclose(row[key], value, rel_tol=1e-12) for key, value in zip(
                            ("baseline_p99_ns", "candidate_p99_ns", "p99_ratio"), (b, c, b / c)))
            row["eligible"] = bool(good and not invalid)
            invalid |= not good
            rows.append(row)
    identities = {name: entry["sha256"] for name, entry in source.items()
                  if name in ("baseline", "candidate", "composer_lock", "benchmark", "rust_cargo_lock", "rust_cargo_toml",
                              "rust_accumulator", "rust_abi", "rust_worker", "php_worker", "native_library", "native_worker",
                              "coordinator_binary", "php_binary", "vendor_installed", "xml_elements")
                  and isinstance(entry, dict) and digest(entry.get("sha256"))}
    complete = len(rows) == 2 * len(FINGERPRINT_FIXTURES)
    result = dict(rows=rows, status="invalid" if invalid else "measured" if complete else "partial",
                completeness=dict(expected_rows=2 * len(FINGERPRINT_FIXTURES), observed_rows=len(rows), complete=complete),
                baseline_revision=revision, source_sha256=identities,
                protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                evidence_type="paired_public_fingerprint_median_and_tail_screen")
    if report.get("candidate_status") == "experimental_withdrawn_from_production":
        result["candidate_status"] = "experimental_withdrawn_from_production"
    return result


def resource_receipt():
    """Verify the current run's retained endpoints and export only their hashes."""
    spec = importlib.util.spec_from_file_location('benchmark_resources', Path(__file__).with_name('benchmark-resource-limits.py'))
    resources = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(resources)
    return resources.verified_receipt(Path('utility-evidence'))



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
            protocol = report.get('protocol', {})
            current = (isinstance(protocol, dict) and (kind.startswith('fingerprint-') and protocol.get('version') == 4
                       or kind == 'cpu-snapshot' and count(protocol.get('host_pairs_opt_in')) and protocol['host_pairs_opt_in'] > 0))
            if current:
                try:
                    summary.setdefault('source_sha256', {}).update(resource_receipt())
                except (OSError, UnicodeError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
                    summary['status'] = 'invalid'
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
        if kind == "cpu-snapshot" and (summary["status"] not in ("measured", "measured_gate_failed") or "runtime_identity" not in summary):
            for field in ("confidence_status", "host_confidence_status"):
                if summary.get(field) == "simultaneous_gate_intervals":
                    summary[field] = "unqualified"
        if summary['status'] not in ('measured', 'measured_gate_failed'):
            for row in summary['rows']:
                row['eligible'] = False
                for gate in ('gate_passed', 'p99_threshold_met'):
                    if row.get(gate) is True:
                        row[gate] = False
                if 'decision' in row:
                    row['decision'] = 'invalid'
        return summary
    summary.update(provenance_status="incomplete", hardware_qualification="unavailable")
    records, malformed = [], False
    report_hash = hashlib.sha256()
    try:
        with Path(INPUTS[kind]).open("rb") as source:
            for line in source:
                report_hash.update(line)
                try:
                    record = json.loads(line, parse_constant=reject_constant, parse_float=finite_float)
                    if not isinstance(record, dict):
                        malformed = True
                    else:
                        records.append(record)
                except (ValueError, TypeError, UnicodeError):
                    malformed = True
    except FileNotFoundError:
        return unavailable_cpp_hardware(summary) if kind == "cpp" else summary
    except (OSError, UnicodeError):
        malformed = True
    rows, status, completeness, flags = summarize(kind, records)
    summary.update(rows=rows, status="invalid" if malformed else status, completeness=completeness, **flags, **identities(records))
    summary["report_sha256"] = report_hash.hexdigest()
    try:
        evidence = measurement_provenance(kind, summary, records)
        sources = summary.get("source_sha256", {}) | evidence.pop("source_sha256")
        summary.update(evidence, source_sha256=sources)
        if summary["provenance_status"] == "verified_experiment" and summary["status"] in CURRENT_STATUSES:
            summary["status"] = "experimental_measurement"
    except FileNotFoundError:
        if summary["status"] in CURRENT_STATUSES:
            summary["status"] = "incomplete_provenance"
    except (OSError, UnicodeError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
        summary.update(status="invalid", provenance_status="invalid", hardware_qualification="unavailable")
    if summary["provenance_status"] not in ("verified_current", "verified_experiment") or summary["status"] in ("invalid", "incomplete_provenance"):
        for row in rows:
            if "eligible" in row:
                row["eligible"] = False
            for gate in ("gate_passed", "qualification_gate_passed"):
                if gate in row:
                    row[gate] = False
            if "decision" in row:
                row["decision"] = "invalid"
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
