"""Public caller benchmark exports retain uncertainty and exclude raw payloads."""

import copy
import json
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/benchmark_callers_summary.py"
SPEC = importlib.util.spec_from_file_location('utility_fixture', Path(__file__).with_name('test_benchmark_utility_extended.py'))
UTILITY_FIXTURE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UTILITY_FIXTURE)
MARKER = "unpublished-synthetic-payload"
SAMPLE = {
    "small-dispatch": "dispatch_control", "boundary-1024": "common",
    "large-16384": "common", "duplicates-inferred": "uncommon",
    "tight-budget": "uncommon", "unsupported-coercion": "fallback_control",
}
ARCHIVE = {name: "common" if index < 2 else "uncommon" for index, name in enumerate(
    ("public-rename-index", "synthetic-128", "synthetic-512", "synthetic-1024"))}
ARCHIVE.update(dict.fromkeys(("rar4", "rar5", "zip", "seven-zip", "iso", "seven-zip-truncated",
    "seven-zip-corrupt", "seven-ranges-complete", "seven-ranges-split", "seven-ranges-missing",
    "seven-ranges-conflicting", "par2-ranges-complete", "par2-ranges-split", "par2-ranges-missing",
    "par2-ranges-conflicting", "par2-ranges-checksum"), "diagnostic"))


def fixture(kind, samples=31, mode="screen"):
    definitions = SAMPLE if kind == "sample-planning" else ARCHIVE
    records = [{"type": "protocol", "protocol": kind + "-v1", "mode": mode,
                "samples_per_stratum": samples, "instrumentation": {MARKER: "a" * 64}},
               {"type": "setup", "build_source_identities": {"composer.lock": "b" * 64, MARKER: "c" * 64},
                "library": {"sha256": "d" * 64, "path": MARKER}}]
    decisions = {}
    for name, category in definitions.items():
        size = min(samples, 31) if category == "diagnostic" else samples
        decision = {}
        for phase in ("cold", "warm"):
            if category == "diagnostic":
                row = {"status": "diagnostic_only", "eligible_observations": size,
                       "invalid_observations": 0, "native_ms": [2.0] * size, "qualifies": False}
            elif category.endswith("control"):
                row = {"status": "not_applicable", "eligible_pairs": size,
                       "invalid_pairs": 0, "qualifies": False, "reason": category,
                       "php_median_ms": 1.0, "candidate_fallback_median_ms": 1.5}
            else:
                metric = "p99_latency" if category == "uncommon" else "median_latency"
                insufficient = category == "uncommon" and size < 1000
                interval = [None, None] if insufficient else [1.6, 4.0]
                marginal = {"estimate": 5.0, "interval": [4.0, 6.0], "samples": size,
                            "ranks": [1, size], "confidence": 0.996875}
                native = dict(marginal, estimate=2.0, interval=[1.5, 2.5])
                if insufficient:
                    marginal["interval"][1] = None
                    native["interval"][1] = None
                row = {"status": "inconclusive", "eligible_pairs": size, "invalid_pairs": 0,
                       "metric": metric, "php": marginal, "native": native,
                       "php_over_native": 2.5, "simultaneous_95_percent_speedup_interval": interval,
                       "qualifies": False, "evidence": "screen_only_no_adoption"}
            decision[phase] = row
        decisions[name] = decision
        for pair in range(size):
            row = {"type": "pair", "stratum": name, "pair": pair, "eligible": True}
            if kind == "sample-planning":
                row.update(exact_serialized_parity=True, native_admission_expected=not category.endswith("control"))
            else:
                row.update(shared_projection_parity=None if category == "diagnostic" else True, paired=category != "diagnostic")
            records.append(row)
        records.append({"type": "stratum", "stratum": name, "category": category, "decision": decision})
    records.append({"type": "summary", "status": "inconclusive" if kind == "sample-planning" else "partial_semantics_only",
                    "identities_stable": True, "invalid_pairs": 0, "invalid_observations_or_pairs": 0,
                    "strata": decisions, "acceptance_criteria_met": False, "par2_shared_projection_qualified": False,
                    "reason": MARKER})
    return records


class CallerSummaryExportTest(unittest.TestCase):
    def export(self, kind, records, resources=None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "utility-evidence" / kind / "observations.jsonl"
            source.parent.mkdir(parents=True)
            source.write_text("".join(json.dumps(row) + "\n" for row in records))
            UTILITY_FIXTURE.write_resources(root, resources)
            (root / "utility-evidence/runtime.json").write_text(json.dumps({
                "executed_sha": "e" * 40, "architecture": "aarch64", "php": "8.5", MARKER: MARKER}))
            env = dict(os.environ, GITHUB_SHA="e" * 40, GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="1",
                       GITHUB_REPOSITORY="xbmc4lyfe/newznab-tmux")
            result = subprocess.run(["python3", str(SCRIPT), "--kind", kind, "--runner", "ubuntu-24.04-arm",
                                     "--output", "summary.json"], cwd=root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            text = (root / "summary.json").read_text()
            self.assertNotIn(MARKER, text)
            summary = json.loads(text)
            self.assertIs(summary["adoption_qualified"], False)
            return summary

    def test_current_caller_resource_receipt_cannot_be_missing_changed_or_forged(self):
        for kind in ('sample-planning', 'archive-parsing'):
            for mutation in ('missing', 'changed', 'forged'):
                summary = self.export(kind, fixture(kind), resources=mutation)
                self.assertEqual(summary['status'], 'invalid')
                self.assertFalse(any(row['eligible'] for row in summary['rows']))
                self.assertFalse(any(row.get('qualification_gate_passed') for row in summary['rows']))
            summary = self.export(kind, fixture(kind))
            self.assertEqual({key for key in summary['source_sha256'] if key.startswith('resource_limits_')},
                             {'resource_limits_before', 'resource_limits_after', 'resource_limits_comparison'})

    def test_complete_sample_keeps_controls_and_unknown_tail(self):
        summary = self.export("sample-planning", fixture("sample-planning"))
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(len(summary["rows"]), 12)
        tail = next(row for row in summary["rows"] if row["fixture"] == "tight-budget")
        self.assertFalse(tail["p99_sample_sufficient"])
        self.assertEqual(tail["speedup_interval"], [None, None])
        self.assertEqual(tail["metric"], "p99_unavailable")
        self.assertNotIn("baseline_over_candidate", tail)
        self.assertNotIn("candidate_latency_ms", tail)
        self.assertEqual(sum(row["comparison"] == "same_php_control" for row in summary["rows"]), 4)
        control = next(row for row in summary["rows"] if row["fixture"] == "unsupported-coercion")
        self.assertEqual(control["candidate_latency_ms"], 1.5)
        self.assertNotIn("baseline_over_candidate", control)

    def test_archives_report_shared_projection_and_native_only_diagnostics(self):
        summary = self.export("archive-parsing", fixture("archive-parsing"))
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(len(summary["rows"]), 40)
        self.assertEqual(sum(row["comparison"] == "native_only_diagnostic" for row in summary["rows"]), 32)
        for row in summary["rows"]:
            if row["comparison"] == "native_only_diagnostic":
                self.assertNotIn("baseline_over_candidate", row)
                self.assertEqual(row["native_median_ms"], 2.0)

    def test_missing_terminal_and_stratum_remain_partial(self):
        for kind in ("sample-planning", "archive-parsing"):
            self.assertEqual(self.export(kind, fixture(kind)[:-1])["status"], "partial")
            rows = fixture(kind)
            del rows[next(index for index, row in enumerate(rows) if row["type"] == "stratum")]
            self.assertEqual(self.export(kind, rows)["status"], "partial")

    def test_duplicate_pairs_and_invalid_observations_cannot_look_complete(self):
        for kind in ("sample-planning", "archive-parsing"):
            rows = fixture(kind)
            pair = next(row for row in rows if row["type"] == "pair")
            rows.insert(2, copy.deepcopy(pair))
            self.assertEqual(self.export(kind, rows)["status"], "invalid")
            rows = fixture(kind)
            next(row for row in rows if row["type"] == "pair")["eligible"] = False
            self.assertEqual(self.export(kind, rows)["status"], "invalid")

    def test_source_drift_and_nonfinite_metrics_fail_closed(self):
        for kind in ("sample-planning", "archive-parsing"):
            rows = fixture(kind)
            rows[-1]["identities_stable"] = False
            self.assertEqual(self.export(kind, rows)["status"], "invalid")
            rows = fixture(kind)
            next(row for row in rows if row["type"] == "stratum")["decision"]["cold"]["php_over_native"] = float("nan")
            self.assertEqual(self.export(kind, rows)["status"], "invalid")

    def test_qualification_bounds_do_not_turn_partial_semantics_into_adoption(self):
        for kind in ("sample-planning", "archive-parsing"):
            rows = fixture(kind, samples=1000, mode="qualification")
            for record in rows:
                if record.get("type") == "stratum" and record["category"] in ("common", "uncommon"):
                    for decision in record["decision"].values():
                        decision.update(status="qualified", qualifies=True, evidence="qualification")
            if kind == "sample-planning":
                rows[-1].update(status="qualified", acceptance_criteria_met=True)
            else:
                rows[-1]["par2_shared_projection_qualified"] = True
            summary = self.export(kind, rows)
            self.assertEqual(summary["status"], "measured")
            measured = [row for row in summary["rows"] if row["category"] in ("common", "uncommon")]
            self.assertTrue(all(row["qualification_gate_passed"] for row in measured))
            self.assertTrue(all(row["p99_sample_sufficient"] for row in measured))


if __name__ == "__main__":
    unittest.main()
