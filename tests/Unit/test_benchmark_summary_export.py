"""Public evidence export contracts, exercised through the actual CLI."""

import importlib.util
import copy
import hashlib
import itertools
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/export-benchmark-summary.py"
RUST_SOURCES = {str(path.relative_to(SCRIPT.parents[1])): "a" * 64 for path in
                (SCRIPT.parents[1] / "docker/app/nzb-yenc-ffi/src").rglob("*.rs")}
INPUTS = {"fingerprint-ffi": "utility-evidence/fingerprint-ffi/report.json", "fingerprint-worker": "utility-evidence/fingerprint-worker/report.json", "cpu-snapshot": "utility-evidence/cpu-snapshot/report.json", "cpp": "benchmark-evidence/paired/observations.jsonl", "php": "benchmark-evidence/php-paired/observations.jsonl", "profile": "processing-evidence/profile/observations.jsonl", "presentation": "benchmark-evidence/results.jsonl"}
# Literal test sentinel; no credentials or runtime configuration are read.
UNPUBLISHED_MARKER = "synthetic-unpublished-fixture"


def paired(kind="cpp"):
    records = [{"type": "protocol", "source_sha256": {"composer.lock": "a" * 64, UNPUBLISHED_MARKER: "b" * 64}, "libraries": {"rust": {"sha256": "c" * 64, "path": UNPUBLISHED_MARKER}}, "runtime": {"unpublished_fixture": UNPUBLISHED_MARKER}}]
    for size, density, path in itertools.product((4096, 524288, 1048576, 8388608), ("ordinary", "heavy"), ("tolerant", "strict", "body")):
        records.append({"type": "stratum_summary", "size": size, "density": density, "path": path, "decision": "useful_improvement", "eligible_pairs": 31, "invalid_pairs": 0, "median_rust_over_" + kind: 0.7, "bootstrap_95_percent_interval": [0.6, 0.8], "command": UNPUBLISHED_MARKER})
    records.append({"type": "run_summary", "status": "useful_improvement", "strata": 24, "claim_limit": UNPUBLISHED_MARKER})
    return records


def profile():
    records = [{"type": "protocol", "command": UNPUBLISHED_MARKER}]
    for size, density, phase in itertools.product((4096, 524288, 1048576), ("ordinary", "heavy"), ("full-analyzer", "framing", "headers", "body-metadata", "relationship", "raw-body", "whole-body")):
        records.append({"type": "observation", "size": size, "density": density, "phase": phase, "eligible": True, "status": "ok", "result": {"status": "ok", "median_ms": 2.5, "min_ms": 2, "max_ms": 3, "observations": [{"warmup": False, "elapsed_ms": 2.5}], "metadata": UNPUBLISHED_MARKER}, "command": [UNPUBLISHED_MARKER]})
    records.append({"type": "summary", "status": "ok", "invalid_observations": 0, "source_and_library_identities_stable": True, "instrumentation_identities_stable": True})
    return records


def presentation():
    records = []
    for scenario, rows in itertools.product(("clean", "wrapped", "escaped"), (1, 2, 5, 10, 16, 50, 100)):
        record = {"scenario": scenario, "rows": rows, "candidate_backend": "php-small-batch" if rows < 16 else "rust", "source_sha256": {"composer.lock": "a" * 64}, "observations": [UNPUBLISHED_MARKER]}
        if rows < 16:
            record.update(correctness_passed=True, performance_gate="not_applicable_same_php_path")
        else:
            record.update(samples=21, iterations=100, php_median_ns=100, rust_median_ns=80, improvement_percent=20, gate_passed=True)
        records.append(record)
    return records


class BenchmarkSummaryExportTest(unittest.TestCase):
    def test_rust_source_allowlist_matches_committed_crate_inventory(self):
        spec = importlib.util.spec_from_file_location("benchmark_summary_export", SCRIPT)
        exporter = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(exporter)
        committed = set(subprocess.check_output(
            ["git", "ls-files", "-z", "--", "docker/app/nzb-yenc-ffi/src"],
            cwd=SCRIPT.parents[1],
        ).decode().rstrip("\0").split("\0"))
        committed = {path for path in committed if path.endswith(".rs")}
        self.assertEqual(exporter.RUST_SOURCE_PATHS, committed)

    def test_payload_modes_follow_the_recorded_manifest_and_unsafe_or_ambiguous_modes_are_unknown(self):
        for kind in ("cpp", "php", "profile"):
            for supplied, expected in (("experimental", "experimental"), ("incumbent", "incumbent"),
                                       (None, "unknown"), (UNPUBLISHED_MARKER, "unknown"), ([], "unknown")):
                records = profile() if kind == "profile" else paired(kind)
                manifest = {} if supplied is None else {"rust_payload_mode": supplied}
                if kind == "profile":
                    records[0]["runtime_manifest"] = manifest
                else:
                    records[0]["runtime"] = {"manifest": {"contents": manifest}}
                with self.subTest(kind=kind, supplied=supplied):
                    self.assertEqual(expected, self.run_export(kind, records)["rust_payload_mode"])
            records = profile() if kind == "profile" else paired(kind)
            records.insert(1, dict(records[0]))
            self.assertEqual("unknown", self.run_export(kind, records)["rust_payload_mode"])
            self.assertEqual("unknown", self.run_export(kind)["rust_payload_mode"])


    def test_same_commit_keeps_experimental_cpp_and_incumbent_php_and_profile_distinct_on_pages(self):
        spec = importlib.util.spec_from_file_location("pages_mode_fixture", SCRIPT.with_name("benchmark-pages.py"))
        pages = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(pages)
        summaries = []
        run = {"head_sha": "d" * 40, "id": 123, "run_attempt": 2}
        for kind, mode in (("cpp", "experimental"), ("php", "incumbent"), ("profile", "incumbent")):
            records = profile() if kind == "profile" else paired(kind)
            if kind == "profile":
                records[0]["runtime_manifest"] = {"rust_payload_mode": mode}
            else:
                records[0]["runtime"] = {"manifest": {"contents": {"rust_payload_mode": mode}}}
            summary = self.run_export(kind, records)
            pages.validate_summary(summary, run, kind, "linux-x64")
            self.assertEqual(mode, summary["rust_payload_mode"])
            summaries.append(summary)
        rendered = pages.render({"head_sha": "d" * 40, "generated_at": "fixture", "workflows": [],
                                 "coverage": [], "results": summaries})
        self.assertIn("Rust payload mode: experimental", rendered)
        self.assertIn("Rust payload mode: incumbent", rendered)
        self.assertTrue(all(summary["adoption_qualified"] is False for summary in summaries))



    def test_payload_mode_fails_closed_for_failed_malformed_or_duplicate_protocols(self):
        for kind in ("cpp", "php", "profile"):
            records = profile() if kind == "profile" else paired(kind)
            protocol = records[0]
            if kind == "profile":
                protocol["runtime_manifest"] = {"rust_payload_mode": "incumbent"}
            else:
                protocol["runtime"] = {"manifest": {"contents": {"rust_payload_mode": "experimental"}}}
            duplicate = [protocol, *records]
            self.assertEqual("unknown", self.run_export(kind, duplicate)["rust_payload_mode"])
            failure = dict(type="failure" if kind == "profile" else "run_failure", status="invalid")
            self.assertEqual("unknown", self.run_export(kind, [*records, failure])["rust_payload_mode"])
            raw = "".join(json.dumps(row) + "\n" for row in records) + "{unfinished"
            self.assertEqual("unknown", self.run_export(kind, raw=raw)["rust_payload_mode"])
            protocol["runtime_manifest" if kind == "profile" else "runtime"] = []
            self.assertEqual("unknown", self.run_export(kind, records)["rust_payload_mode"])

    def run_export(self, kind, records=None, raw=None, runtime=None, runtime_raw=None, resources=None, proof=True, qualification=None, frozen_libraries=True):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            if kind in ("cpp", "php", "profile", "presentation") and proof and records is not None:
                records = copy.deepcopy(records)
                if kind == "presentation":
                    runtime = {"git_head": "d" * 40, "github_run_id": "123", "runner_arch": "X64",
                               "rust_source_sha256": dict(RUST_SOURCES), "rust_library_sha256": "c" * 64,
                               "presentation_protocol": {"samples": 21, "iterations": 100, "order_seed": 20261014}, **(runtime or {})}
                    for record in records:
                        record["library_sha256"] = "c" * 64
                        if record.get("candidate_backend") == "rust":
                            record["order_seed"] = 20261014
                            if type(record.get("rows")) is int and record["rows"] > 0:
                                record["iterations"] = max(100, 10000 // record["rows"])
                protocols = [row for row in records if row.get("type") == "protocol"]
                if len(protocols) == 1:
                    protocol = protocols[0]
                    embedded = protocol.get("runtime_manifest", {}) if kind == "profile" else protocol.get("runtime", {}).get("manifest", {}).get("contents", {}) if isinstance(protocol.get("runtime", {}), dict) else None
                    if isinstance(embedded, dict):
                        receipt = {"git_head": "d" * 40, "github_run_id": "123", "runner_arch": "X64",
                                   "rust_source_sha256": dict(RUST_SOURCES), "rust_library_sha256": "c" * 64,
                                   "cpp_library_sha256": "b" * 64, "rust_payload_kernel_id": 3 if kind == "cpp" else 2,
                                   "rustc": "rustc 1.98.0", "php": "PHP 8.5", **embedded}
                        if runtime is not None:
                            receipt.update(runtime)
                        if kind == "profile":
                            protocol["runtime_manifest"] = receipt
                            protocol["sources"] = {**RUST_SOURCES, **protocol.get("sources", {})}
                            protocol["library"] = {"sha256": "c" * 64}
                        else:
                            protocol["runtime"] = {"manifest": {"contents": receipt}}
                            protocol["source_sha256"] = {**RUST_SOURCES, **protocol.get("source_sha256", {})}
                            protocol["libraries"] = {"rust": {"sha256": "c" * 64}}
                            if kind == "cpp":
                                protocol["libraries"]["cpp"] = {"sha256": "b" * 64}
                        runtime = receipt
                        if kind == "cpp" and qualification is None:
                            qualification = {"status": "qualified", "runner_arch": "X64", "required_kernel_id": 3,
                                "observed_kernel": {"id": 3, "name": "avx512-vbmi2"}, "git_head": "d" * 40,
                                "rust_library_sha256": "c" * 64, "cpp_library_sha256": "b" * 64}
            if kind in ("cpp", "php", "profile", "presentation") and runtime is not None:
                runtime_path = root / ("processing-evidence/build/runtime.json" if kind == "profile" else "benchmark-evidence/build/runtime.json")
                runtime_path.parent.mkdir(parents=True, exist_ok=True)
                runtime_path.write_text(runtime_raw if runtime_raw is not None else json.dumps(runtime))
            if qualification is not None:
                qualification_path = root / "benchmark-evidence/build/kernel-qualification.json"
                qualification_path.parent.mkdir(parents=True, exist_ok=True)
                qualification_path.write_text(json.dumps(qualification))
                if qualification.get("status") == "unsupported_hardware" and frozen_libraries:
                    for backend in ("rust", "cpp"):
                        (qualification_path.parent / (backend + ".so")).write_bytes(("fixture-" + backend).encode())
            if records is not None or raw is not None:
                source = root / INPUTS[kind]
                source.parent.mkdir(parents=True, exist_ok=True)
                source.write_text(raw if raw is not None else json.dumps(records) if kind in ("fingerprint-ffi", "fingerprint-worker", "cpu-snapshot") else "".join(json.dumps(row) + "\n" for row in records))
            if kind in ("fingerprint-ffi", "fingerprint-worker", "cpu-snapshot"):
                runtime_path = root / "utility-evidence/runtime.json"
                runtime_path.parent.mkdir(parents=True, exist_ok=True)
                runtime_path.write_text(runtime_raw if runtime_raw is not None else json.dumps(runtime if runtime is not None else {"executed_sha": "d" * 40, "rustc": "rustc 1.98.0", "build_profile": "release", "command": UNPUBLISHED_MARKER}))
                if resources is not None:
                    spec = importlib.util.spec_from_file_location("utility_resource_fixture", Path(__file__).with_name("test_benchmark_utility_extended.py"))
                    fixture = importlib.util.module_from_spec(spec)
                    spec.loader.exec_module(fixture)
                    fixture.write_resources(root, resources)
            env = dict(os.environ, GITHUB_SHA="d" * 40, GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="2", GITHUB_REPOSITORY="xbmc4lyfe/newznab-tmux")
            result = subprocess.run(["python3", "-B", str(SCRIPT), "--kind", kind, "--runner", "linux-x64", "--output", "summary.json"], cwd=root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            content = (root / "summary.json").read_text()
            self.assertNotIn(UNPUBLISHED_MARKER, content)
            summary = json.loads(content)
            self.assertIs(summary["adoption_qualified"], False)
            self.assertEqual(summary["head_sha"], "d" * 40)
            self.assertEqual((summary["run_id"], summary["run_attempt"]), (123, 2))
            self.assertEqual(summary["repository"], "xbmc4lyfe/newznab-tmux")
            return summary

    def test_yenc_exports_full_implementation_and_frozen_receipt_provenance(self):
        for kind in ("cpp", "php", "profile"):
            records = profile() if kind == "profile" else paired(kind)
            summary = self.run_export(kind, records)
            self.assertEqual(summary.get("provenance_status"), "verified_current")
            self.assertEqual(summary["runtime_identity"]["executed_sha"], "d" * 40)
            self.assertEqual(summary["source_sha256"]["docker/app/nzb-yenc-ffi/src/payload_decoder.rs"], "a" * 64)
            self.assertEqual(summary["source_sha256"]["docker/app/nzb-yenc-ffi/src/yenc_framing.rs"], "a" * 64)
            for field in ("protocol_sha256", "report_sha256", "runtime_manifest_sha256"):
                self.assertRegex(summary[field], "^[a-f0-9]{64}$")
            self.assertEqual(summary["rust_payload_kernel"], {"id": 3, "name": "avx512-vbmi2"} if kind == "cpp" else {"id": 2, "name": "avx2"})
            self.assertEqual(summary["hardware_qualification"], "designated_kernel" if kind == "cpp" else "observed_only")

    def test_yenc_legacy_and_wrong_source_receipts_cannot_claim_current_measurements(self):
        for kind in ("cpp", "php", "profile"):
            records = profile() if kind == "profile" else paired(kind)
            legacy = self.run_export(kind, records, proof=False)
            self.assertEqual(legacy["status"], "incomplete_provenance")
            self.assertEqual(legacy["provenance_status"], "incomplete")
            self.assertEqual(legacy["rust_payload_mode"], "unknown")
            wrong = self.run_export(kind, records, runtime={"git_head": "e" * 40})
            self.assertEqual(wrong["status"], "invalid")
            self.assertEqual(wrong["provenance_status"], "invalid")

    def test_bulk_verification_sources_remain_required_without_qualifying_adoption(self):
        paths = ("docker/app/nzb-yenc-ffi/src/bulk_verification.rs",
                 "docker/app/nzb-yenc-ffi/src/bin/nntmux-verification-framer.rs")
        for kind in ("cpp", "php", "profile", "presentation"):
            records = (profile() if kind == "profile" else presentation() if kind == "presentation"
                       else paired(kind))
            if kind == "presentation":
                for record in records:
                    record["source_sha256"].update(dict.fromkeys(paths, "a" * 64))
            with self.subTest(kind=kind):
                summary = self.run_export(kind, records)
                self.assertEqual(summary["provenance_status"], "verified_current")
                self.assertIs(summary["adoption_qualified"], False)
                for path in paths:
                    self.assertEqual(summary["source_sha256"][path], "a" * 64)
            for path in paths:
                missing = dict(RUST_SOURCES)
                del missing[path]
                changed = {**RUST_SOURCES, path: "f" * 64}
                for label, sources in (("missing", missing), ("mismatched", changed)):
                    with self.subTest(kind=kind, path=path, receipt=label):
                        rejected = self.run_export(kind, records, runtime={"rust_source_sha256": sources})
                        self.assertEqual(rejected["status"], "invalid")
                        self.assertEqual(rejected["provenance_status"], "invalid")
                        self.assertEqual(rejected["rust_payload_mode"], "unknown")
                        self.assertIs(rejected["adoption_qualified"], False)

    def test_profile_keeps_experiment_candidate_source_separate(self):
        summary = self.run_export("profile", profile(), runtime={"candidate_git_head": "e" * 40,
            "candidate_source_sha256": dict(RUST_SOURCES), "candidate_library_sha256": "c" * 64,
            "candidate_payload_kernel_id": 2})
        self.assertEqual(summary["head_sha"], "d" * 40)
        self.assertEqual(summary.get("candidate_head_sha"), "e" * 40)
        self.assertEqual(summary["provenance_status"], "verified_experiment")
        self.assertEqual(summary["status"], "experimental_measurement")

    def test_yenc_rejects_receipt_source_library_and_runtime_disagreements(self):
        bad_sources = dict(RUST_SOURCES)
        bad_sources["docker/app/nzb-yenc-ffi/src/payload_decoder.rs"] = "f" * 64
        missing_sources = dict(RUST_SOURCES)
        del missing_sources["docker/app/nzb-yenc-ffi/src/zstd_frame.rs"]
        missing_new_path = dict(RUST_SOURCES)
        del missing_new_path["docker/app/nzb-yenc-ffi/src/abi_crc.rs"]
        extra_source = dict(RUST_SOURCES)
        extra_source["docker/app/nzb-yenc-ffi/src/not-committed.rs"] = "f" * 64
        for change in ({"rust_source_sha256": bad_sources}, {"rust_source_sha256": missing_sources},
                       {"rust_source_sha256": missing_new_path}, {"rust_source_sha256": extra_source},
                       {"rust_library_sha256": "f" * 64}, {"github_run_id": "124"},
                       {"rust_payload_kernel_id": True}, {"rust_payload_kernel_id": 2.0}):
            with self.subTest(change=change):
                self.assertEqual(self.run_export("php", paired("php"), runtime=change)["status"], "invalid")
        mismatch = self.run_export("php", paired("php"), runtime_raw=json.dumps({"git_head": "d" * 40}))
        self.assertEqual(mismatch["status"], "invalid")
        current_candidate_mismatch = self.run_export("profile", profile(), runtime={"candidate_git_head": "d" * 40,
            "candidate_source_sha256": bad_sources, "candidate_library_sha256": "c" * 64})
        self.assertEqual(current_candidate_mismatch["status"], "invalid")

    def test_private_and_unreviewed_source_names_do_not_enter_public_provenance(self):
        rows = paired("php")
        private = (".env", "/tmp/private-source.rs", "docker/app/nzb-yenc-ffi/src/../../private.rs",
                   "docker/app/nzb-yenc-ffi/src/unreviewed.rs")
        rows[0]["source_sha256"].update(dict.fromkeys(private, "f" * 64))
        summary = self.run_export("php", rows)
        self.assertEqual(summary["status"], "useful_improvement")
        for path in private:
            self.assertNotIn(path, json.dumps(summary))

    def unsupported_receipts(self):
        runtime = {"git_head": "d" * 40, "github_run_id": "123", "runner_arch": "X64",
                   "rust_source_sha256": dict(RUST_SOURCES), "rust_library_sha256": hashlib.sha256(b"fixture-rust").hexdigest(),
                   "cpp_library_sha256": hashlib.sha256(b"fixture-cpp").hexdigest(), "rust_payload_mode": "incumbent",
                   "rust_payload_kernel": {"id": 2, "name": "avx2", "basis": UNPUBLISHED_MARKER}}
        qualification = {"status": "unsupported_hardware", "runner_arch": "X64", "required_kernel_id": 3,
                         "observed_kernel": {"id": 2, "name": "avx2"}, "git_head": "d" * 40,
                         "rust_library_sha256": runtime["rust_library_sha256"], "cpp_library_sha256": runtime["cpp_library_sha256"]}
        return runtime, qualification

    def test_cpp_unsupported_hardware_requires_matching_frozen_receipts(self):
        runtime, qualification = self.unsupported_receipts()
        summary = self.run_export("cpp", runtime=runtime, qualification=qualification)
        self.assertEqual(summary["status"], "unsupported_hardware")
        self.assertEqual(summary["provenance_status"], "verified_current")
        self.assertEqual(summary["hardware_qualification"], "unsupported_hardware")
        self.assertEqual(summary["rust_payload_kernel"], {"id": 2, "name": "avx2"})
        self.assertEqual(summary["required_payload_kernel"], {"id": 3, "name": "avx512-vbmi2"})
        self.assertEqual(summary["rows"], [])
        self.assertRegex(summary["kernel_qualification_sha256"], "^[a-f0-9]{64}$")
        for change in ({"git_head": "e" * 40}, {"rust_library_sha256": "f" * 64},
                       {"observed_kernel": {"id": 2.0, "name": "avx2"}},
                       {"required_kernel_id": 2}, {"observed_kernel": {"id": 2, "name": "unsafe"}}):
            with self.subTest(change=change):
                bad = self.run_export("cpp", runtime=runtime, qualification={**qualification, **change})
                self.assertNotEqual(bad["status"], "unsupported_hardware")
        missing = self.run_export("cpp", runtime=runtime)
        self.assertNotEqual(missing["status"], "unsupported_hardware")
        missing_library = self.run_export("cpp", runtime=runtime, qualification=qualification, frozen_libraries=False)
        self.assertNotEqual(missing_library["status"], "unsupported_hardware")

    def test_presentation_provenance_ties_full_native_graph_and_recorded_protocol(self):
        summary = self.run_export("presentation", presentation())
        self.assertEqual(summary.get("provenance_status"), "verified_current")
        self.assertEqual(summary["runtime_identity"]["executed_sha"], "d" * 40)
        self.assertEqual(summary["source_sha256"]["docker/app/nzb-yenc-ffi/src/xz_first_block.rs"], "a" * 64)
        for field in ("protocol_sha256", "report_sha256", "runtime_manifest_sha256"):
            self.assertRegex(summary[field], "^[a-f0-9]{64}$")
        self.assertEqual(self.run_export("presentation", presentation(), proof=False)["status"], "incomplete_provenance")
        bad = self.run_export("presentation", presentation(), runtime={"presentation_protocol": {"samples": 22, "iterations": 100, "order_seed": 20261014}})
        self.assertEqual(bad["status"], "invalid")

    def test_malformed_presentation_rows_still_export_invalid_public_json(self):
        for value in (0, [], "16", True):
            rows = presentation()
            rows[-1]["rows"] = value
            self.assertEqual(self.run_export("presentation", rows)["status"], "invalid")

    def utility(self, kind):
        cpu = kind == "cpu-snapshot"
        names = ("common-mounted", "rare-deep64", "rare-unavailable") if cpu else ("ids16", "ids4096", "ids10000-unique-plus-duplicates", "ids50000", "xml10000")
        digest = "44186d8c2cf2f72f63f41d46bab30e0d78b683a43441c802c8e62280fdf03812" if cpu else "2c24de1374bc2f734961d3a7159fb13eaca175839f565a14004bcd41d7f3a374"
        report = dict(benchmark="cpu-snapshot-cold-cli" if cpu else "nzb-article-fingerprint-paired-comparison",
                      protocol=dict(version=2 if cpu else 3, pairs=1000, pairs_per_fixture=31, backend=kind.removeprefix("fingerprint-")),
                      sources={"baseline": {"sha256": digest, "path": UNPUBLISHED_MARKER}},
                      runtime={"command": UNPUBLISHED_MARKER}, observations=[], invalid_observations=[], changed_artifacts=[], correctness_pass=True, adoption_gate_pass=False)
        report["summary"] = {}
        for name in names:
            if cpu:
                report["summary"][name] = dict(valid_pairs=1000, required_pairs=1000, baseline_median_ns=200, candidate_median_ns=100, median_ratio=2, paired_median_ratio=2, baseline_p99_ns=400, candidate_p99_ns=200, p99_ratio=2, median_ratio_ci95=[1.8, 2.2], p99_ratio_ci95=[1.7, 2.3], p99_sample_sufficient=True, gate_pass=False)
            else:
                report["summary"][name] = dict(pairs_total=31, pairs_valid=31, median_baseline_over_candidate_ratio=2, median_cold_baseline_over_candidate_ratio=1.5, pairs=[{"eligible": True}] * 31)
        return report

    def test_cpu_protocol_versions_distinguish_historical_and_corrected_confidence(self):
        old = self.run_export("cpu-snapshot", self.utility("cpu-snapshot"))
        self.assertEqual(old.get("measurement_protocol_version"), 2)
        self.assertEqual(old["confidence_status"], "historical_unqualified")
        report = self.utility("cpu-snapshot")
        report["protocol"].update(version=3, bootstrap_rng="splitmix64-rejection",
                                  order_rng="splitmix64-rejection", bootstrap_resamples=4000,
                                  bootstrap_seed=0, confidence_family_size=3,
                                  confidence_method="paired_bootstrap_bonferroni")
        for name, row in report["summary"].items():
            row.update(gate_statistic="median_ratio" if name == "common-mounted" else "p99_ratio",
                       gate_speedup_simultaneous_ci95=[1.6, 2.4])
        new = self.run_export("cpu-snapshot", report)
        self.assertEqual(new["status"], "measured_gate_failed")
        self.assertEqual(new["confidence_status"], "simultaneous_gate_intervals")
        self.assertEqual(new["confidence_family_size"], 3)
        self.assertEqual(new["rows"][0]["gate_speedup_simultaneous_ci95"], [1.6, 2.4])
        bad_runtime = self.run_export("cpu-snapshot", report, runtime={"executed_sha": "e" * 40})
        self.assertEqual(bad_runtime["status"], "invalid")
        self.assertEqual(bad_runtime["confidence_status"], "unqualified")
        bad_json = self.run_export("cpu-snapshot", report, runtime_raw="{unfinished")
        self.assertEqual(bad_json["status"], "invalid")
        self.assertEqual(bad_json["confidence_status"], "unqualified")
        for field, bad in (("bootstrap_rng", UNPUBLISHED_MARKER), ("order_rng", []),
                           ("bootstrap_resamples", 2000), ("confidence_family_size", 4),
                           ("bootstrap_seed", -1), ("confidence_method", UNPUBLISHED_MARKER)):
            original = report["protocol"][field]
            report["protocol"][field] = bad
            with self.subTest(field=field):
                rejected = self.run_export("cpu-snapshot", report)
                self.assertEqual(rejected["status"], "invalid")
                self.assertEqual(rejected["confidence_status"], "unqualified")
            report["protocol"][field] = original

    def corrected_cpu_host(self):
        report = self.utility("cpu-snapshot")
        report["protocol"].update(version=3, host_pairs_opt_in=1000, bootstrap_rng="splitmix64-rejection",
                                  order_rng="splitmix64-rejection", bootstrap_resamples=4000, bootstrap_seed=0,
                                  confidence_family_size=3, confidence_method="paired_bootstrap_bonferroni")
        for name, row in report["summary"].items():
            row.update(gate_statistic="median_ratio" if name == "common-mounted" else "p99_ratio",
                       gate_speedup_simultaneous_ci95=[1.6, 2.4])
        report.update(host_gate_pass=True, overall_adoption_qualified=False)
        metrics = dict(report["summary"]["common-mounted"])
        metrics.pop("gate_statistic")
        metrics.pop("gate_speedup_simultaneous_ci95")
        metrics.update(baseline_p99_ns=200, candidate_p99_ns=100, gate_pass=True,
                       live_median_speedup_simultaneous_ci95=[1.8, 2.2],
                       live_p99_speedup_simultaneous_ci95=[1.7, 2.3])
        host_protocol = dict(version=3, pairs=1000, exact_default_cli=True, bootstrap_rng="splitmix64-rejection",
                             order_rng="splitmix64-rejection", bootstrap_resamples=4000, bootstrap_seed=20261014,
                             confidence_method="paired_bootstrap_bonferroni", confidence_family_size=2,
                             confidence_level=0.95, median_ratio_ci_lower_gate=1.5, p99_ratio_ci_lower_gate=1.5)
        report["host_comparison"] = dict(enabled=True, correctness_pass=True, source_integrity_pass=True,
            host_gate_pass=True, protocol=host_protocol, summary=metrics,
            observations=[dict(pair=pair, implementation=backend, warmup=pair == 0, valid=True,
                               elapsed_ns=200 if backend == "baseline" else 100, validation={"valid": True})
                          for pair, backend in itertools.product(range(1001), ("baseline", "candidate"))])
        return report

    def test_live_host_protocol3_has_an_independent_two_gate_family(self):
        report = self.corrected_cpu_host()
        summary = self.run_export("cpu-snapshot", report, resources="valid")
        self.assertEqual(summary["status"], "measured_gate_failed")
        self.assertEqual(summary["confidence_status"], "simultaneous_gate_intervals")
        self.assertEqual(summary["confidence_family_size"], 3)
        self.assertEqual(summary["host_confidence_status"], "simultaneous_gate_intervals")
        self.assertEqual(summary["host_confidence_family_size"], 2)
        spec = importlib.util.spec_from_file_location("pages_live_family_fixture", SCRIPT.with_name("benchmark-pages.py"))
        pages = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(pages)
        pages.validate_summary(summary, {"head_sha": "d" * 40, "id": 123, "run_attempt": 2}, "cpu-snapshot", "linux-x64")
        self.assertTrue(all(row["eligible"] for row in summary["rows"]))
        host = next(row for row in summary["rows"] if row["fixture"] == "host-live")
        self.assertTrue(host["gate_passed"])
        self.assertNotIn("gate_speedup_simultaneous_ci95", host)
        self.assertEqual(host["live_p99_speedup_simultaneous_ci95"], [1.7, 2.3])
        # Favorable diagnostic intervals cannot override a negative simultaneous decision.
        report["host_comparison"]["summary"].update(live_p99_speedup_simultaneous_ci95=[1.4, 2.3], gate_pass=False)
        report["host_comparison"]["host_gate_pass"] = report["host_gate_pass"] = False
        negative = self.run_export("cpu-snapshot", report, resources="valid")
        self.assertEqual(negative["status"], "measured_gate_failed")
        self.assertEqual(negative["host_confidence_status"], "simultaneous_gate_intervals")
        self.assertTrue(all(row["eligible"] for row in negative["rows"]))
        self.assertFalse(next(row for row in negative["rows"] if row["fixture"] == "host-live")["gate_passed"])
        # A small live-host screen does not alter the synthetic family's qualification.
        report["protocol"]["host_pairs_opt_in"] = 15
        report["host_comparison"]["protocol"]["pairs"] = 15
        report["host_comparison"]["summary"].update(valid_pairs=15, required_pairs=15, p99_sample_sufficient=False)
        report["host_comparison"]["observations"] = [row for row in report["host_comparison"]["observations"] if row["pair"] <= 15]
        small = self.run_export("cpu-snapshot", report, resources="valid")
        self.assertEqual(small["status"], "measured_gate_failed")
        self.assertEqual(small["confidence_status"], "simultaneous_gate_intervals")
        self.assertEqual(small["host_confidence_status"], "unqualified")
        self.assertTrue(all(row["eligible"] for row in small["rows"]))

    def test_live_host_protocol3_rejects_missing_or_malformed_confidence_provenance(self):
        bad_fields = (("version", 2), ("order_rng", []), ("bootstrap_rng", UNPUBLISHED_MARKER),
                      ("bootstrap_resamples", 2000), ("bootstrap_seed", -1),
                      ("confidence_family_size", 3), ("confidence_method", "pointwise"),
                      ("confidence_level", 0.9), ("median_ratio_ci_lower_gate", 2),
                      ("p99_ratio_ci_lower_gate", 1))
        for field, value in bad_fields:
            for missing in (False, True):
                with self.subTest(field=field, missing=missing):
                    report = self.corrected_cpu_host()
                    if missing:
                        del report["host_comparison"]["protocol"][field]
                    else:
                        report["host_comparison"]["protocol"][field] = value
                    summary = self.run_export("cpu-snapshot", report, resources="valid")
                    self.assertEqual(summary["status"], "invalid")
                    self.assertEqual(summary["host_confidence_status"], "unqualified")
                    self.assertFalse(any(row["eligible"] or row.get("gate_passed") for row in summary["rows"]))

    def test_live_host_protocol3_global_failure_clears_all_affirmative_results(self):
        for failure in ("missing-interval", "malformed-interval", "gate-mismatch", "partial", "runtime", "resources"):
            with self.subTest(failure=failure):
                report, options = self.corrected_cpu_host(), {"resources": "valid"}
                if failure == "missing-interval":
                    del report["host_comparison"]["summary"]["live_median_speedup_simultaneous_ci95"]
                elif failure == "malformed-interval":
                    report["host_comparison"]["summary"]["live_p99_speedup_simultaneous_ci95"] = [2.3, 1.7]
                elif failure == "gate-mismatch":
                    report["host_gate_pass"] = False
                elif failure == "partial":
                    del report["summary"]["rare-deep64"]
                elif failure == "runtime":
                    options["runtime"] = {"executed_sha": "e" * 40}
                else:
                    options["resources"] = "missing"
                summary = self.run_export("cpu-snapshot", report, **options)
                self.assertEqual(summary["status"], "partial" if failure == "partial" else "invalid")
                self.assertEqual(summary["confidence_status"], "unqualified")
                self.assertEqual(summary["host_confidence_status"], "unqualified")
                self.assertFalse(any(row["eligible"] or row.get("gate_passed") for row in summary["rows"]))
                self.assertTrue(any(row.get("baseline_median_ns") == 200 for row in summary["rows"]))

    def test_legacy_cpu_identity_is_preserved_without_inventing_a_protocol(self):
        report = self.utility("cpu-snapshot")
        summary = self.run_export("cpu-snapshot", report)
        self.assertEqual(summary["confidence_status"], "historical_unqualified")
        self.assertEqual(summary["report_sha256"], hashlib.sha256(json.dumps(report).encode()).hexdigest())
        for field in ("version", "pairs"):
            changed = copy.deepcopy(report)
            del changed["protocol"][field]
            summary = self.run_export("cpu-snapshot", changed)
            self.assertEqual(summary["status"], "invalid")
            self.assertFalse(any(row["eligible"] for row in summary["rows"]))
            if field == "version":
                self.assertEqual(summary["measurement_protocol_version"], 0)
                self.assertEqual(summary["confidence_status"], "unqualified")

    def test_corrected_cpu_confidence_requires_sufficient_declared_and_valid_pairs(self):
        for declared, samples, sufficient, qualified in (
                (1, 1, False, False), (15, 15, False, False),
                (999, 1000, True, False), (1000, 999, False, False),
                (1000, 1000, None, False), (1000, 1000, False, False),
                (1000, 1000, True, True)):
            with self.subTest(declared=declared, samples=samples, sufficient=sufficient):
                report = self.utility("cpu-snapshot")
                report["protocol"].update(version=3, pairs=declared,
                                          bootstrap_rng="splitmix64-rejection", order_rng="splitmix64-rejection",
                                          bootstrap_resamples=4000, bootstrap_seed=0, confidence_family_size=3,
                                          confidence_method="paired_bootstrap_bonferroni")
                for name, row in report["summary"].items():
                    row.update(gate_statistic="median_ratio" if name == "common-mounted" else "p99_ratio",
                               gate_speedup_simultaneous_ci95=[1.6, 2.4],
                               valid_pairs=samples, required_pairs=declared)
                    if sufficient is None:
                        row.pop("p99_sample_sufficient")
                    else:
                        row["p99_sample_sufficient"] = sufficient
                summary = self.run_export("cpu-snapshot", report)
                self.assertEqual(summary["confidence_status"],
                                 "simultaneous_gate_intervals" if qualified else "unqualified")
                self.assertEqual(summary["rows"][0]["baseline_median_ns"], 200)
                if declared == samples and sufficient is False and samples < 1000:
                    self.assertEqual(summary["status"], "measured_gate_failed")
                # One insufficient stratum must also disqualify the whole family.
                if qualified:
                    report["summary"]["rare-unavailable"].update(valid_pairs=15, p99_sample_sufficient=False)
                    self.assertEqual(self.run_export("cpu-snapshot", report)["confidence_status"], "unqualified")

    def test_corrected_exact_default_host_has_five_gates_and_retains_both_intervals(self):
        report = self.utility("cpu-snapshot")
        report["protocol"].update(version=3, host_proc_absent_at_start=True,
                                  bootstrap_rng="splitmix64-rejection", order_rng="splitmix64-rejection",
                                  bootstrap_resamples=4000, bootstrap_seed=0, confidence_family_size=5,
                                  host_absent_median_ratio_ci_lower_gate=2.0,
                                  host_absent_p99_ratio_ci_lower_gate=1.5,
                                  confidence_method="paired_bootstrap_bonferroni")
        report["summary"]["rare-host-unavailable"] = dict(report["summary"]["rare-unavailable"])
        for name, row in report["summary"].items():
            row.update(gate_statistic="median_ratio" if name == "common-mounted" else "p99_ratio",
                       gate_speedup_simultaneous_ci95=[1.6, 2.4])
        report["summary"]["rare-host-unavailable"]["host_median_speedup_simultaneous_ci95"] = [1.8, 2.4]
        summary = self.run_export("cpu-snapshot", report)
        self.assertEqual(summary["status"], "measured_gate_failed")
        self.assertEqual(summary["confidence_family_size"], 5)
        host = next(row for row in summary["rows"] if row["fixture"] == "rare-host-unavailable")
        self.assertEqual(host["host_median_speedup_simultaneous_ci95"], [1.8, 2.4])
        del report["summary"]["rare-host-unavailable"]["host_median_speedup_simultaneous_ci95"]
        self.assertEqual(self.run_export("cpu-snapshot", report)["status"], "invalid")

    def test_native_utility_reports_are_bounded_and_do_not_claim_adoption(self):
        for kind in ("fingerprint-ffi", "fingerprint-worker", "cpu-snapshot"):
            summary = self.run_export(kind, self.utility(kind))
            self.assertIn(summary["status"], ("measured", "measured_gate_failed"))
            self.assertTrue(summary["completeness"]["complete"])
            self.assertEqual(len(summary["rows"]), 3 if kind == "cpu-snapshot" else 5)
            self.assertEqual(summary["source_sha256"]["baseline"], self.utility(kind)["sources"]["baseline"]["sha256"])
            self.assertEqual(summary["rows"][0]["samples"], 1000 if kind == "cpu-snapshot" else 31)
            self.assertIn("baseline_revision", summary)

    def test_withdrawn_fingerprint_status_is_public_without_copying_freeform_scope(self):
        for kind in ("fingerprint-ffi", "fingerprint-worker"):
            report = self.utility(kind)
            report["candidate_status"] = "experimental_withdrawn_from_production"
            report["comparison_scope"] = UNPUBLISHED_MARKER
            summary = self.run_export(kind, report)
            self.assertEqual(summary["candidate_status"], "experimental_withdrawn_from_production")
            self.assertFalse(summary["adoption_qualified"])
            self.assertNotIn("comparison_scope", summary)
            report["candidate_status"] = UNPUBLISHED_MARKER
            self.assertEqual(self.run_export(kind, report)["status"], "invalid")

    def test_full_fingerprint_withdrawal_preserves_metrics_and_unknown_historical_status(self):
        spec = importlib.util.spec_from_file_location("withdrawn_fingerprint_fixture", Path(__file__).with_name("test_benchmark_utility_extended.py"))
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        for kind, tail in itertools.product(("fingerprint-ffi", "fingerprint-worker"), (None, 1000)):
            with self.subTest(kind=kind, tail=tail):
                report = fixture.fingerprint(tail)
                report["protocol"]["backend"] = kind.removeprefix("fingerprint-")
                historical = self.run_export(kind, report, resources="valid")
                self.assertEqual(historical["status"], "measured")
                self.assertNotIn("candidate_status", historical)
                report["candidate_status"] = "experimental_withdrawn_from_production"
                report["comparison_scope"] = UNPUBLISHED_MARKER
                summary = self.run_export(kind, report, resources="valid")
                self.assertEqual(summary["candidate_status"], "experimental_withdrawn_from_production")
                self.assertEqual(summary["status"], "measured")
                self.assertEqual(summary["rows"], historical["rows"])
                self.assertEqual(len(summary["rows"]), 18)
                self.assertEqual(sum(row["p99_sample_sufficient"] for row in summary["rows"]), 6 if tail else 0)
                self.assertFalse(summary["adoption_qualified"])
                self.assertNotIn("comparison_scope", summary)

    def test_full_fingerprint_malformed_candidate_status_disqualifies_every_row(self):
        spec = importlib.util.spec_from_file_location("malformed_withdrawal_fixture", Path(__file__).with_name("test_benchmark_utility_extended.py"))
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        for value in (None, [], {}, True, "production", UNPUBLISHED_MARKER):
            with self.subTest(value=value):
                report = fixture.fingerprint(1000)
                report["candidate_status"] = value
                summary = self.run_export("fingerprint-ffi", report, resources="valid")
                self.assertEqual(summary["status"], "invalid")
                self.assertFalse(summary["adoption_qualified"])
                self.assertNotIn("candidate_status", summary)
                self.assertFalse(any(row["eligible"] or row.get("p99_threshold_met") for row in summary["rows"]))
                self.assertTrue(all(row["decision"] == "invalid" for row in summary["rows"]))
                self.assertTrue(all(row["baseline_median_ns"] == 4 for row in summary["rows"]))

    def test_native_utility_wrong_baseline_backend_missing_fixture_and_failures(self):
        for kind in ("fingerprint-ffi", "fingerprint-worker", "cpu-snapshot"):
            report = self.utility(kind)
            report["sources"]["baseline"]["sha256"] = "0" * 64
            self.assertEqual(self.run_export(kind, report)["status"], "invalid")
            report = self.utility(kind)
            del report["summary"][next(iter(report["summary"]))]
            self.assertEqual(self.run_export(kind, report)["status"], "partial")
            report = self.utility(kind)
            report["summary"][UNPUBLISHED_MARKER] = {}
            self.assertEqual(self.run_export(kind, report)["status"], "invalid")
            report = self.utility(kind)
            if kind == "cpu-snapshot":
                report["correctness_pass"] = False
            else:
                report["invalid_observations"] = [{"command": UNPUBLISHED_MARKER}]
            self.assertEqual(self.run_export(kind, report)["status"], "invalid")
        report = self.utility("fingerprint-ffi")
        report["protocol"]["backend"] = "worker"
        self.assertEqual(self.run_export("fingerprint-ffi", report)["status"], "invalid")

    def test_native_utility_invalid_metrics_and_insufficient_tail_are_visible(self):
        report = self.utility("cpu-snapshot")
        report["summary"]["common-mounted"]["valid_pairs"] = 0
        self.assertEqual(self.run_export("cpu-snapshot", report)["status"], "invalid")
        report = self.utility("cpu-snapshot")
        report["summary"]["common-mounted"]["p99_ratio"] = float("nan")
        self.assertEqual(self.run_export("cpu-snapshot", report)["status"], "invalid")
        summary = self.run_export("fingerprint-ffi", self.utility("fingerprint-ffi"))
        self.assertTrue(all(row["p99_sample_sufficient"] is False for row in summary["rows"]))

    def test_cpu_optional_absent_proc_host_fixture_is_preserved(self):
        report = self.utility("cpu-snapshot")
        report["protocol"]["host_proc_absent_at_start"] = True
        self.assertEqual(self.run_export("cpu-snapshot", report)["status"], "partial")
        report["summary"]["rare-host-unavailable"] = dict(report["summary"]["rare-unavailable"])
        summary = self.run_export("cpu-snapshot", report)
        self.assertEqual(summary["status"], "measured_gate_failed")
        self.assertEqual(len(summary["rows"]), 4)

    def test_utility_runtime_identity_is_current_and_allowlisted(self):
        summary = self.run_export("fingerprint-ffi", self.utility("fingerprint-ffi"))
        self.assertEqual(summary["runtime_identity"]["executed_sha"], "d" * 40)
        self.assertNotIn("command", summary["runtime_identity"])
        self.assertEqual(self.run_export("fingerprint-ffi", self.utility("fingerprint-ffi"), runtime={"executed_sha": "e" * 40})["status"], "invalid")
        self.assertEqual(len(summary["report_sha256"]), 64)
        self.assertEqual(len(summary["protocol_sha256"]), 64)

    def test_missing_inputs_always_produce_json(self):
        for kind in INPUTS:
            with self.subTest(kind=kind):
                self.assertEqual(self.run_export(kind)["status"], "missing")

    def test_complete_paired_metric_summary_and_safe_hashes(self):
        for kind in ("cpp", "php"):
            summary = self.run_export(kind, paired(kind))
            self.assertEqual(summary["status"], "useful_improvement")
            self.assertEqual(len(summary["rows"]), 24)
            self.assertEqual(summary["source_sha256"], {**RUST_SOURCES, "composer.lock": "a" * 64})
            self.assertEqual(summary["library_sha256"], {"rust": "c" * 64, **({"cpp": "b" * 64} if kind == "cpp" else {})})
            self.assertEqual(summary["rows"][0]["median_rust_over_" + kind], 0.7)

    def test_missing_final_summary_is_partial(self):
        self.assertEqual(self.run_export("cpp", paired()[:-1])["status"], "partial")

    def test_missing_stratum_with_terminal_is_partial(self):
        rows = paired()
        del rows[2]
        summary = self.run_export("cpp", rows)
        self.assertEqual(summary["status"], "partial")
        self.assertFalse(summary["completeness"]["complete"])

    def test_invalid_pair_and_invalid_metric_retained(self):
        rows = paired()
        rows[1]["invalid_pairs"] = 1
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")
        rows[1]["eligible_pairs"] = None
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")

    def test_malformed_nonobject_and_nonfinite_json(self):
        for raw in ("{unfinished", "[]\n", "null\n", "\n"):
            self.assertEqual(self.run_export("cpp", raw=raw)["status"], "invalid")
        rows = paired()
        rows[1]["median_rust_over_cpp"] = float("nan")
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")

    def test_duplicate_and_unknown_labels_are_invalid_and_private(self):
        rows = paired()
        rows.insert(2, dict(rows[1]))
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")
        rows[1]["density"] = UNPUBLISHED_MARKER
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")

    def test_profile_complete_and_stability_gate(self):
        rows = profile()
        summary = self.run_export("profile", rows)
        self.assertEqual(summary["status"], "ok")
        self.assertEqual(len(summary["rows"]), 42)
        self.assertEqual(summary["rows"][0]["median_ms"], 2.5)
        rows[-1]["instrumentation_identities_stable"] = False
        self.assertEqual(self.run_export("profile", rows)["status"], "invalid")

    def test_profile_missing_observation_and_final_summary(self):
        rows = profile()
        del rows[1]
        self.assertEqual(self.run_export("profile", rows)["status"], "partial")
        self.assertEqual(self.run_export("profile", profile()[:-1])["status"], "partial")

    def test_body_bridge_medians_sample_count_and_exclusion(self):
        rows = profile()
        body = next(row for row in rows if row.get("phase") == "body-metadata")
        result = body["result"]
        result["observations"] = [
            {"warmup": True, "elapsed_ms": 900},
            {"warmup": False, "elapsed_ms": 1.0, "metadata": UNPUBLISHED_MARKER},
            {"warmup": False, "elapsed_ms": 2.0},
            {"warmup": False, "elapsed_ms": "3.0"},
            {"warmup": False, "elapsed_ms": -1},
        ]
        stages = ("allocate_and_memcpy", "native_parse_and_serialize", "ffi_string_and_free", "json_decode")
        result["body_ffi_breakdown"] = [
            {"warmup": False, "correct": True, "stages_ms": dict.fromkeys(stages, value) | {UNPUBLISHED_MARKER: 123}, "source": UNPUBLISHED_MARKER}
            for value in (1, 3, 7)
        ]
        result["body_ffi_breakdown"] += [
            {"warmup": True, "correct": True, "stages_ms": dict.fromkeys(stages, 999)},
            {"warmup": False, "correct": False, "stages_ms": dict.fromkeys(stages, 999)},
            {"warmup": False, "correct": True, "stages_ms": dict.fromkeys(stages, -1)},
            {"warmup": False, "correct": True, "stages_ms": dict.fromkeys(stages, "123")},
        ]
        summary = self.run_export("profile", rows)
        exported = next(row for row in summary["rows"] if row.get("phase") == "body-metadata")
        self.assertEqual(summary["status"], "ok")
        self.assertEqual(exported["samples"], 2)
        self.assertEqual(exported["body_ffi_median_ms"], dict.fromkeys(stages, 3))

    def test_nonfinite_unknown_fields_are_invalid(self):
        for value in ("NaN", "Infinity", "-Infinity", "1e999"):
            raw = "".join(json.dumps(row) + "\n" for row in paired())
            raw = raw.replace('"type": "protocol"', '"type": "protocol", "ignored": ' + value, 1)
            self.assertEqual(self.run_export("cpp", raw=raw)["status"], "invalid")

    def test_huge_integer_is_invalid_metric_without_crash(self):
        rows = paired()
        rows[1]["median_rust_over_cpp"] = 10 ** 400
        self.assertEqual(self.run_export("cpp", rows)["status"], "invalid")

    def test_presentation_complete_partial_and_gate_failure(self):
        rows = presentation()
        summary = self.run_export("presentation", rows)
        self.assertEqual(summary["status"], "passed")
        self.assertEqual(sum(row["candidate_backend"] == "rust" for row in summary["rows"]), 9)
        self.assertEqual(sum(row["candidate_backend"] == "php-small-batch" for row in summary["rows"]), 12)
        self.assertEqual(self.run_export("presentation", rows[:-1])["status"], "partial")
        rows[-1]["gate_passed"] = False
        self.assertEqual(self.run_export("presentation", rows)["status"], "gate_failed")


if __name__ == "__main__":
    unittest.main()
