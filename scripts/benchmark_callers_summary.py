#!/usr/bin/env python3
"""Export fixed public caller metrics; raw evidence never leaves the allowlist."""

import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import statistics

CALLER_STRATA = {
    "sample-planning": {"small-dispatch": "dispatch_control", "boundary-1024": "common", "large-16384": "common",
                        "duplicates-inferred": "uncommon", "tight-budget": "uncommon", "unsupported-coercion": "fallback_control"},
    "vendor-archive": {name: 'common' for name in ('rar4-stored', 'rar5-mixed', 'zip-stored', 'seven-stored')}
        | {name: 'uncommon' for name in ('rar4-solid', 'rar5-encrypted-headers', 'zip-aes', 'seven-encrypted-headers')},
    "archive-parsing": {"public-rename-index": "common", "synthetic-128": "common", "synthetic-512": "uncommon", "synthetic-1024": "uncommon",
                        **dict.fromkeys(("rar4", "rar5", "zip", "seven-zip", "iso", "seven-zip-truncated", "seven-zip-corrupt",
                                        "seven-ranges-complete", "seven-ranges-split", "seven-ranges-missing", "seven-ranges-conflicting",
                                        "par2-ranges-complete", "par2-ranges-split", "par2-ranges-missing", "par2-ranges-conflicting", "par2-ranges-checksum"), "diagnostic")},
}


VENDOR_INPUTS = {
    'rar4-stored': ('rar/store_method.rar', 'c32c1bb9c65159df7a8cedd939e7e42d3fd424e334898e586c1558093bc89d37'),
    'rar5-mixed': ('rar/rar50_quickopen.rar', '645f6a8adfae57ff04b3e2ff0fbf018101e623646b4396d3cd605437d3b5f882'),
    'zip-stored': ('zip/little_file.zip', 'ababf71680cceb96c44ee04d69ab5dd99436c3b05c1d60242ffb8994e171ff09'),
    'seven-stored': ('szip/store_method.7z', 'ec369e350984c9af3f5211f6cc20008abf29f7f331f8aa656604be05fafede5a'),
    'rar4-solid': ('rar/solid.rar', '05d39c43a4ba897ea358543aa5b700208eba307ee6e6379d8d7cbd978aa374e4'),
    'rar5-encrypted-headers': ('rar/rar50_encrypted_headers.rar', 'f5db452c3d7dc351b1ff9971edfb0611893838affa369e31e209570e270cc2af'),
    'zip-aes': ('zip/encrypted_file_aes.zip', '7555457edd346b22c718e43d3409e1b9ae1dca1b118a26b1cdb960e58781238d'),
    'seven-encrypted-headers': ('szip/encrypted_headers.7z', '4b7fdbb7b875847278e65bed4ce23fc29a149a7a7e93334304dbf34fb8d52f0d'),
}
VENDOR_INPUTS = {name: ('vendor/dariusiii/rarinfo/tests/fixtures/' + path, digest) for name, (path, digest) in VENDOR_INPUTS.items()}
VENDOR_SOURCES = {'composer.lock', 'vendor/composer/installed.json', 'vendor/dariusiii/rarinfo/composer.json',
    'vendor/dariusiii/rarinfo/ArchiveReader.php', 'vendor/dariusiii/rarinfo/RarInfo.php',
    'vendor/dariusiii/rarinfo/ZipInfo.php', 'vendor/dariusiii/rarinfo/SzipInfo.php',
    'scripts/benchmark-vendor-archive.php', 'tests/Fixtures/VendorArchiveBenchmarkFixtures.php'} | {path for path, _ in VENDOR_INPUTS.values()}


SOURCE_PATHS = {
    "composer.json", "app/Services/Releases/Fingerprint/Par2MetadataParser.php",
    "app/Services/Releases/Verification/SpotCheckPlanner.php", "app/Services/Releases/Verification/NativeSpotCheckPlanner.php",
    "scripts/benchmark-sample-planning.php", "scripts/benchmark-archive-parsing.php",
    "tests/Fixtures/SamplePlanningBenchmarkFixtures.php", "tests/Fixtures/ArchiveParsingBenchmarkFixtures.php",
    "tests/Fixtures/par2/rename-index.par2", "docker/app/nzb-yenc-ffi/src/par2.rs", "docker/app/nzb-yenc-ffi/src/formats.rs",
    "docker/app/nzb-yenc-ffi/src/seven_zip_ranges.rs", "docker/app/nzb-yenc-ffi/src/par2_source_ranges.rs", "docker/app/nzb-yenc-ffi/src/sample_plan.rs",
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

SOURCE_PATHS |= VENDOR_SOURCES


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


def count(value):
    return type(value) is int and value >= 0


def digest(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


def valid_vendor_setup(protocol, records):
    declarations = protocol.get('strata')
    if not isinstance(declarations, dict) or set(declarations) != set(VENDOR_INPUTS):
        return False
    for name, (path, expected_hash) in VENDOR_INPUTS.items():
        row = declarations[name]
        if (not isinstance(row, dict) or row.get('category') != CALLER_STRATA['vendor-archive'][name]
                or row.get('path') != path or row.get('sha256') != expected_hash or row.get('paired') is not True):
            return False
    setups = [row for row in records if row.get('type') == 'setup']
    if len(setups) != 1:
        return False
    hashes, receipt = setups[0].get('build_source_identities'), setups[0].get('vendor_receipt')
    if not isinstance(hashes, dict) or not all(digest(hashes.get(key)) for key in VENDOR_SOURCES) or not isinstance(receipt, dict):
        return False
    files = receipt.get('files')
    expected_files = {key: hashes[key] for key in VENDOR_SOURCES if key.startswith('vendor/') or key == 'composer.lock'}
    return (all(hashes[path] == expected_hash for path, expected_hash in VENDOR_INPUTS.values())
            and receipt.get('package') == 'dariusiii/rarinfo' and receipt.get('version') in ('3.1', 'v3.1')
            and receipt.get('license') == ['BSD-3-Clause']
            and receipt.get('source_reference') == '2cd3220b76d807de1798a98d743f20224f30d63a'
            and isinstance(files, dict) and files == expected_files)


def valid_vendor_quantiles(source, samples):
    alpha = 0.05 / 32
    if not number(source.get('marginal_alpha')) or not math.isclose(source['marginal_alpha'], alpha, rel_tol=1e-12):
        return False
    quantiles = [source.get(key) for key in ('php', 'native')]
    if not all(isinstance(value, dict) and value.get('samples') == samples
               and number(value.get('confidence')) and math.isclose(value['confidence'], 1 - alpha, rel_tol=1e-12)
               and number(value.get('estimate')) and value['estimate'] > 0 for value in quantiles):
        return False
    for value in quantiles:
        interval = value.get('interval')
        if (not isinstance(interval, list) or len(interval) != 2
                or any(bound is not None and (not number(bound) or bound <= 0) for bound in interval)
                or interval[0] is not None and interval[0] > value['estimate']
                or interval[1] is not None and interval[1] < value['estimate']):
            return False
    baseline, candidate = (value['interval'] for value in quantiles)
    expected = [baseline[0] / candidate[1] if baseline[0] is not None and candidate[1] is not None else None,
                baseline[1] / candidate[0] if baseline[1] is not None and candidate[0] is not None else None]
    interval = source.get('simultaneous_95_percent_speedup_interval')
    return (isinstance(interval, list) and len(interval) == 2
            and all(actual is None if required is None else number(actual) and math.isclose(actual, required, rel_tol=1e-12)
                    for actual, required in zip(interval, expected)))


def disqualify_rows(rows):
    for row in rows:
        row['eligible'] = False
        if 'qualification_gate_passed' in row:
            row['qualification_gate_passed'] = False


def caller_summary(kind, records):
    """Fixed public metric projection of the PHP caller harnesses."""
    expected = CALLER_STRATA[kind]
    protocols = [row for row in records if row.get("type") == "protocol"]
    terminal = records[-1] if records and records[-1].get("type") == "summary" else {}
    invalid = len(protocols) > 1
    protocol = protocols[0] if len(protocols) == 1 else {}
    samples = protocol.get("samples_per_stratum")
    valid_protocol = (protocol.get("protocol") == kind + "-v1" and count(samples) and 1 <= samples <= 10000
                      and protocol.get("mode") in ("screen", "qualification")
                      and (protocol["mode"] != "qualification" or samples >= 1000))
    invalid |= bool(protocol) and not valid_protocol
    if kind == 'vendor-archive':
        invalid |= not valid_vendor_setup(protocol, records)
    measured, pairs = {}, {key: set() for key in expected}
    for record in records:
        if record.get("type") not in ("stratum", "pair"):
            continue
        name = record.get("stratum")
        if not isinstance(name, str) or name not in expected:
            invalid = True
            continue
        if record["type"] == "stratum":
            invalid |= name in measured or record.get("category") != expected[name]
            measured[name] = record.get("decision")
        else:
            pair = record.get("pair")
            size = min(samples, 31) if valid_protocol and expected[name] == "diagnostic" else samples
            if not valid_protocol or not count(pair) or pair >= size or pair in pairs[name]:
                invalid = True
                continue
            pairs[name].add(pair)
            invalid |= record.get("eligible") is not True
            if kind == "sample-planning":
                invalid |= record.get("exact_serialized_parity") is not True
                invalid |= record.get("native_admission_expected") is not (not expected[name].endswith("control"))
            else:
                paired = expected[name] != "diagnostic"
                invalid |= record.get("paired") is not paired
                invalid |= record.get("shared_projection_parity") is not (True if paired else None)
    rows = []
    for name, category in expected.items():
        decision = measured.get(name)
        if decision is None:
            continue
        if not isinstance(decision, dict) or set(decision) != {"cold", "warm"}:
            invalid = True
            continue
        size = min(samples, 31) if valid_protocol and category == "diagnostic" else samples
        for phase in ("cold", "warm"):
            source = decision[phase]
            row = dict(fixture=name, phase=phase, candidate_backend="rust", samples=len(pairs[name]),
                       category=category, p99_sample_sufficient=len(pairs[name]) >= 1000)
            good = valid_protocol and len(pairs[name]) == size and isinstance(source, dict)
            if not isinstance(source, dict):
                row.update(eligible=False, comparison="invalid")
                rows.append(row)
                invalid = True
                continue
            if category == "diagnostic":
                row["comparison"] = "native_only_diagnostic"
                values = source.get("native_ms")
                good &= (source.get("status") == "diagnostic_only" and source.get("qualifies") is False
                         and source.get("eligible_observations") == size and source.get("invalid_observations") == 0
                         and isinstance(values, list) and len(values) == size
                         and all(number(value) and value > 0 for value in values))
                if good:
                    row["native_median_ms"] = statistics.median(values)
            elif category.endswith("control"):
                row.update(comparison="same_php_control", candidate_backend="php", metric="not_applicable")
                good &= (source.get("status") == "not_applicable" and source.get("qualifies") is False
                         and source.get("eligible_pairs") == size and source.get("invalid_pairs") == 0)
                for source_key, public_key in (("php_median_ms", "baseline_latency_ms"), ("candidate_fallback_median_ms", "candidate_latency_ms")):
                    if number(source.get(source_key)) and source[source_key] > 0:
                        row[public_key] = source[source_key]
                    else:
                        good = False
            else:
                row["comparison"] = "shared_vendor_header_projection" if kind == "vendor-archive" else "shared_par2_projection" if kind == "archive-parsing" else "exact_public_caller"
                row["metric"] = "p99_latency" if category == "uncommon" else "median_latency"
                good &= (source.get("status") in ("qualified", "inconclusive") and source.get("metric") == row["metric"]
                         and source.get("eligible_pairs") == size and source.get("invalid_pairs") == 0
                         and type(source.get("qualifies")) is bool)
                ratio = source.get("php_over_native")
                interval = source.get("simultaneous_95_percent_speedup_interval")
                good &= number(ratio) and ratio > 0
                good &= (isinstance(interval, list) and len(interval) == 2
                         and all(value is None or number(value) and value > 0 for value in interval)
                         and (None in interval or interval[0] <= interval[1]))
                quantiles = [source.get(key) for key in ("php", "native")]
                good &= all(isinstance(value, dict) and value.get("samples") == size
                            and number(value.get("estimate")) and value["estimate"] > 0 for value in quantiles)
                if kind == 'vendor-archive':
                    good &= valid_vendor_quantiles(source, size)
                    row.update(confidence_family_size=32, marginal_alpha=0.05 / 32)
                if good:
                    row["speedup_interval"] = interval
                    if kind == 'vendor-archive':
                        row.update(baseline_latency_interval_ms=None if category == 'uncommon' and size < 1000 else quantiles[0]['interval'],
                                   candidate_latency_interval_ms=None if category == 'uncommon' and size < 1000 else quantiles[1]['interval'])
                    if category == "uncommon" and size < 1000:
                        row["metric"] = "p99_unavailable"
                        row["speedup_interval"] = [None, None]
                    else:
                        row.update(baseline_over_candidate=ratio, baseline_latency_ms=quantiles[0]["estimate"],
                                   candidate_latency_ms=quantiles[1]["estimate"])
                    good &= math.isclose(ratio, quantiles[0]["estimate"] / quantiles[1]["estimate"], rel_tol=1e-12)
                    required = protocol["mode"] == "qualification" and size >= 1000 and interval[0] is not None and interval[0] >= 1.5
                    good &= source["qualifies"] is required
                    row["qualification_gate_passed"] = bool(required and good)
            row["eligible"] = bool(good)
            invalid |= not good
            rows.append(row)
    complete = len(rows) == 2 * len(expected) and bool(terminal) and valid_protocol
    if terminal:
        invalid |= terminal.get("identities_stable") is not True
        invalid |= terminal.get("status") not in (("qualified", "inconclusive") if kind == "sample-planning" else ("partial_semantics_only",))
        invalid |= terminal.get("invalid_pairs" if kind == "sample-planning" else "invalid_observations_or_pairs") != 0
        if kind == 'vendor-archive':
            invalid |= terminal.get('acceptance_criteria_met') is not False
            expected_gate = complete and all(row.get('qualification_gate_passed') is True for row in rows)
            invalid |= terminal.get('vendor_archive_shared_projection_qualified') is not expected_gate
        declared = terminal.get("strata")
        invalid |= (not isinstance(declared, dict) or set(declared) != set(expected)
                    or any(declared.get(name) != decision for name, decision in measured.items()))
    if invalid or not complete:
        disqualify_rows(rows)
    sources, libraries = {}, {}
    for record in records:
        hashes = record.get("build_source_identities")
        if isinstance(hashes, dict):
            sources.update({key: hashes[key] for key in SOURCE_PATHS if digest(hashes.get(key))})
        library = record.get("library")
        if isinstance(library, dict) and digest(library.get("sha256")):
            libraries["native"] = library["sha256"]
    return dict(rows=rows, status="invalid" if invalid else "measured" if complete else "partial",
                completeness=dict(expected_rows=2 * len(expected), observed_rows=len(rows), complete=complete),
                evidence_type="shared_vendor_archive_header_projection" if kind == "vendor-archive" else "shared_par2_projection_and_native_diagnostics" if kind == "archive-parsing" else "paired_public_caller_quantiles",
                protocol_sha256=hashlib.sha256(json.dumps(protocol, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                source_sha256=sources, library_sha256=libraries)


def env_count(key):
    value = os.environ.get(key, "")
    return int(value) if re.fullmatch(r"[0-9]{1,20}", value) else 0


def export(kind, runner):
    sha = os.environ.get("GITHUB_SHA", "")
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    summary = dict(schema_version=1, kind=kind, runner=runner,
                   head_sha=sha if re.fullmatch(r"[0-9a-f]{40,64}", sha) else "",
                   repository=repository if re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository) else "",
                   run_id=env_count("GITHUB_RUN_ID"), run_attempt=env_count("GITHUB_RUN_ATTEMPT"),
                   adoption_qualified=False, rust_payload_mode="unknown", status="missing", rows=[])
    path = Path("utility-evidence") / kind / "observations.jsonl"
    records, malformed = [], False
    hasher = hashlib.sha256()
    try:
        with path.open("rb") as source:
            for raw in source:
                hasher.update(raw)
                try:
                    record = json.loads(raw, parse_constant=reject_constant, parse_float=finite_float)
                    if isinstance(record, dict):
                        records.append(record)
                    else:
                        malformed = True
                except (ValueError, TypeError, UnicodeError):
                    malformed = True
    except FileNotFoundError:
        return summary
    except (OSError, UnicodeError):
        malformed = True
    summary.update(caller_summary(kind, records))
    summary["report_sha256"] = hasher.hexdigest()
    if malformed:
        summary["status"] = "invalid"
    try:
        spec = importlib.util.spec_from_file_location('benchmark_resources', Path(__file__).with_name('benchmark-resource-limits.py'))
        resources = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(resources)
        summary.setdefault('source_sha256', {}).update(resources.verified_receipt(Path('utility-evidence')))
    except (OSError, UnicodeError, ValueError, TypeError, KeyError, AttributeError, OverflowError):
        summary['status'] = 'invalid'
    try:
        runtime = json.loads(Path("utility-evidence/runtime.json").read_text(), parse_constant=reject_constant, parse_float=finite_float)
        if not isinstance(runtime, dict) or runtime.get("executed_sha") != summary["head_sha"]:
            raise ValueError("Executed build identity mismatch")
        keys = ("executed_sha", "rustc", "cargo", "php", "python", "architecture", "cpu_model", "runner_image_os", "runner_image_version", "build_profile")
        summary["runtime_identity"] = {key: runtime[key] for key in keys if isinstance(runtime.get(key), str) and len(runtime[key]) <= 4096}
        if count(runtime.get("logical_cpus")):
            summary["runtime_identity"]["logical_cpus"] = runtime["logical_cpus"]
    except FileNotFoundError:
        summary["status"] = "partial" if summary["status"] != "invalid" else "invalid"
    except (OSError, UnicodeError, ValueError, TypeError, OverflowError):
        summary["status"] = "invalid"
    if summary['status'] != 'measured':
        disqualify_rows(summary['rows'])
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--kind", choices=CALLER_STRATA, required=True)
    parser.add_argument("--runner", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9_. -]{1,80}", args.runner):
        parser.error("runner must be a short public label")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(export(args.kind, args.runner), indent=2, allow_nan=False) + "\n")


if __name__ == "__main__":
    main()
