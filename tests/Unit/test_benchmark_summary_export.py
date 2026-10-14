"""Public evidence export contracts, exercised through the actual CLI."""

import itertools
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/export-benchmark-summary.py"
INPUTS = {"cpp": "benchmark-evidence/paired/observations.jsonl", "php": "benchmark-evidence/php-paired/observations.jsonl", "profile": "processing-evidence/profile/observations.jsonl", "presentation": "benchmark-evidence/results.jsonl"}
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
        records.append({"type": "observation", "size": size, "density": density, "phase": phase, "eligible": True, "status": "ok", "result": {"status": "ok", "median_ms": 2.5, "min_ms": 2, "max_ms": 3, "metadata": UNPUBLISHED_MARKER}, "command": [UNPUBLISHED_MARKER]})
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
    def run_export(self, kind, records=None, raw=None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            if records is not None or raw is not None:
                source = root / INPUTS[kind]
                source.parent.mkdir(parents=True)
                source.write_text(raw if raw is not None else "".join(json.dumps(row) + "\n" for row in records))
            env = dict(os.environ, GITHUB_SHA="d" * 40, GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="2", GITHUB_REPOSITORY="xbmc4lyfe/newznab-tmux")
            result = subprocess.run(["python3", str(SCRIPT), "--kind", kind, "--runner", "linux-x64", "--output", "summary.json"], cwd=root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            content = (root / "summary.json").read_text()
            self.assertNotIn(UNPUBLISHED_MARKER, content)
            summary = json.loads(content)
            self.assertIs(summary["adoption_qualified"], False)
            self.assertEqual(summary["head_sha"], "d" * 40)
            self.assertEqual((summary["run_id"], summary["run_attempt"]), (123, 2))
            self.assertEqual(summary["repository"], "xbmc4lyfe/newznab-tmux")
            return summary

    def test_missing_inputs_always_produce_json(self):
        for kind in INPUTS:
            with self.subTest(kind=kind):
                self.assertEqual(self.run_export(kind)["status"], "missing")

    def test_complete_paired_metric_summary_and_safe_hashes(self):
        for kind in ("cpp", "php"):
            summary = self.run_export(kind, paired(kind))
            self.assertEqual(summary["status"], "useful_improvement")
            self.assertEqual(len(summary["rows"]), 24)
            self.assertEqual(summary["source_sha256"], {"composer.lock": "a" * 64})
            self.assertEqual(summary["library_sha256"], {"rust": "c" * 64})
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
