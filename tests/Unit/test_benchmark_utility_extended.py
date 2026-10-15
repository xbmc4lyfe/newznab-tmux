"""Extended utility screens retain negative results and distinguish host evidence."""

import copy
import itertools
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/export-benchmark-summary.py"
SPEC = importlib.util.spec_from_file_location('resources', SCRIPT.with_name('benchmark-resource-limits.py'))
RESOURCES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RESOURCES)


def write_resources(root, mutation=None):
    before = dict(schema_version=1, valid=True, captured_at_unix_ns=1,
                  scope=RESOURCES.SCOPE, outer_host_limits='unknown', process_limits='limits',
                  cpu_affinity=[0], shell_soft_limits='soft', shell_hard_limits='hard',
                  cgroup_membership='0::/', mountinfo='fixture',
                  cgroup_v2_ancestors=[dict(cgroup='/', limits=dict.fromkeys(RESOURCES.STATIC_FIELDS, 'max'),
                                          usage=dict.fromkeys(RESOURCES.DYNAMIC_FIELDS, '0'))])
    after = copy.deepcopy(before)
    after['captured_at_unix_ns'] = 2
    left, right = (root / f'utility-evidence/resource-limits-{name}.json' for name in ('before', 'after'))
    if mutation == 'changed':
        after['cpu_affinity'] = [1]
    left.write_text(json.dumps(before))
    right.write_text(json.dumps(after))
    receipt = RESOURCES.compare(left, right)
    if mutation == 'forged':
        receipt.update(valid=True, stable_static_limits=True, outcome='stable-visible-limits')
        after['cpu_affinity'] = [1]
        right.write_text(json.dumps(after))
    if mutation != 'missing':
        (root / 'utility-evidence/resource-limits-comparison.json').write_text(json.dumps(receipt))

MARKER = "unpublished-utility-fixture"
NAMES = ("ids16", "ids4096", "ids10000-unique-plus-duplicates", "ids50000", "xml10000",
         "xml10000-fromXml", "incremental16", "ids16-max255", "incremental25000-max255-spill")
UNCOMMON = {"xml10000-fromXml", "ids16-max255", "incremental25000-max255-spill"}


def fingerprint(tail=None):
    report = {"benchmark": "nzb-article-fingerprint-paired-comparison", "adoption_qualified": False,
              "invalid_observations": [], "protocol": {"version": 4, "coverage": "full", "backend": "ffi",
              "pairs_per_fixture": 31, "tail_pairs_per_uncommon_fixture": tail},
              "sources": {"baseline": {"sha256": "2c24de1374bc2f734961d3a7159fb13eaca175839f565a14004bcd41d7f3a374"}, MARKER: {"sha256": "a" * 64}},
              "summary": {}, "unpublished": MARKER}
    for name in NAMES:
        samples = tail if name in UNCOMMON and tail is not None else 31
        pairs = [{"pair": pair, "eligible": True, "baseline_ns": 4, "candidate_ns": 2,
                  "cold_baseline_ns": 4, "cold_candidate_ns": 2} for pair in range(1, samples + 1)]
        metrics = dict(eligible=True, baseline_median_ns=4, candidate_median_ns=2, paired_median_ratio=2,
                       median_ratio_ci95=[1.8, 2.2], median_sample_sufficient=True, median_decision="useful_improvement",
                       p99_sample_sufficient=samples >= 1000, baseline_p99_ns=4 if samples >= 1000 else None,
                       candidate_p99_ns=2 if samples >= 1000 else None, p99_ratio=2 if samples >= 1000 else None,
                       p99_ratio_ci95=[1.7, 2.3] if samples >= 1000 else None,
                       p99_threshold_met=True if samples >= 1000 else None)
        report["summary"][name] = dict(pairs_total=samples, pairs_valid=samples, pairs=pairs,
                                      cold_metrics=copy.deepcopy(metrics), repeated_metrics=copy.deepcopy(metrics))
    return report


def cpu_host():
    metric = dict(valid_pairs=1000, required_pairs=1000, baseline_median_ns=80, candidate_median_ns=10,
                  median_ratio=8, paired_median_ratio=8, baseline_p99_ns=80, candidate_p99_ns=10, p99_ratio=8,
                  median_ratio_ci95=[7, 9], p99_ratio_ci95=[6, 10], p99_sample_sufficient=True, gate_pass=True)
    return dict(benchmark="cpu-snapshot-cold-cli", protocol=dict(version=2, pairs=1000, host_pairs_opt_in=1000,
                bootstrap_rng="LCG64 state with SplitMix64 output mixing and unbiased rejection sampling"),
                sources={"baseline": {"sha256": "44186d8c2cf2f72f63f41d46bab30e0d78b683a43441c802c8e62280fdf03812"}},
                correctness_pass=True, changed_artifacts=[], adoption_gate_pass=True, host_gate_pass=True,
                overall_adoption_qualified=False, summary={name: copy.deepcopy(metric) for name in ("common-mounted", "rare-deep64", "rare-unavailable")},
                host_comparison=dict(enabled=True, correctness_pass=True, source_integrity_pass=True, host_gate_pass=True,
                    protocol=dict(pairs=1000, exact_default_cli=True), summary=copy.deepcopy(metric),
                    observations=[dict(pair=pair, implementation=backend, warmup=pair == 0, valid=True,
                                       elapsed_ns=80 if backend == "baseline" else 10, validation={"valid": True}, raw=MARKER)
                                  for pair, backend in itertools.product(range(1001), ("baseline", "candidate"))]))


class ExtendedUtilityExportTest(unittest.TestCase):
    def export(self, kind, report, resources=None, runtime='valid'):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "utility-evidence" / kind / "report.json"
            source.parent.mkdir(parents=True)
            source.write_text(json.dumps(report))
            write_resources(root, resources)
            if runtime != 'missing':
                (root / "utility-evidence/runtime.json").write_text(json.dumps({
                    "executed_sha": ("f" if runtime == 'mismatch' else "e") * 40,
                    "architecture": "aarch64", MARKER: MARKER}))
            env = dict(os.environ, GITHUB_SHA="e" * 40, GITHUB_RUN_ID="123", GITHUB_RUN_ATTEMPT="1", GITHUB_REPOSITORY="xbmc4lyfe/newznab-tmux")
            result = subprocess.run(["python3", str(SCRIPT), "--kind", kind, "--runner", "ubuntu-24.04-arm", "--output", "summary.json"],
                                    cwd=root, env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            raw = (root / "summary.json").read_text()
            self.assertNotIn(MARKER, raw)
            result = json.loads(raw)
            self.assertIs(result["adoption_qualified"], False)
            return result

    def test_global_utility_failure_clears_eligibility_and_favorable_gates(self):
        for kind in ('fingerprint-ffi', 'fingerprint-worker', 'cpu-snapshot'):
            report = cpu_host() if kind == 'cpu-snapshot' else fingerprint(1000)
            if kind.startswith('fingerprint-'):
                report['protocol']['backend'] = kind.removeprefix('fingerprint-')
            valid = self.export(kind, report)
            self.assertEqual(valid['status'], 'measured')
            self.assertTrue(all(row['eligible'] for row in valid['rows']))
            self.assertTrue(any(row.get('gate_passed') or row.get('p99_threshold_met') for row in valid['rows']))
            for failure in ('invalid-report', 'partial-report', 'runtime-mismatch', 'runtime-missing'):
                with self.subTest(kind=kind, failure=failure):
                    changed = copy.deepcopy(report)
                    options = {}
                    if failure == 'invalid-report':
                        changed['correctness_pass' if kind == 'cpu-snapshot' else 'invalid_observations'] = False if kind == 'cpu-snapshot' else ['source drift']
                    elif failure == 'partial-report':
                        del changed['summary']['rare-unavailable' if kind == 'cpu-snapshot' else 'ids16']
                    else:
                        options['runtime'] = 'mismatch' if failure == 'runtime-mismatch' else 'missing'
                    summary = self.export(kind, changed, **options)
                    self.assertEqual(summary['status'], 'partial' if failure in ('partial-report', 'runtime-missing') else 'invalid')
                    self.assertFalse(any(row['eligible'] for row in summary['rows']))
                    self.assertFalse(any(row.get('gate_passed') or row.get('p99_threshold_met') for row in summary['rows']))
                    self.assertFalse(any(row.get('decision') == 'useful_improvement' for row in summary['rows']))
                    self.assertTrue(any(row.get('baseline_median_ns', 0) > 0 for row in summary['rows']))

    def test_current_protocol_requires_real_stable_resource_receipts(self):
        for kind, report in (("fingerprint-ffi", fingerprint()), ("cpu-snapshot", cpu_host())):
            for mutation in ('missing', 'changed', 'forged'):
                summary = self.export(kind, report, resources=mutation)
                self.assertEqual(summary['status'], 'invalid')
                self.assertFalse(any(row['eligible'] for row in summary['rows']))
        summary = self.export('fingerprint-ffi', fingerprint())
        self.assertEqual({key for key in summary['source_sha256'] if key.startswith('resource_limits_')},
                         {'resource_limits_before', 'resource_limits_after', 'resource_limits_comparison'})

    def test_full_fingerprint_lifecycles_and_unknown_tails(self):
        summary = self.export("fingerprint-ffi", fingerprint())
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(len(summary["rows"]), 18)
        self.assertEqual({row["phase"] for row in summary["rows"]}, {"cold", "repeated"})
        self.assertTrue(all(row["p99_threshold_met"] is None for row in summary["rows"]))
        summary = self.export("fingerprint-ffi", fingerprint(1000))
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(sum(row["p99_sample_sufficient"] for row in summary["rows"]), 6)

    def test_missing_or_reused_fingerprint_pairs_fail_closed(self):
        report = fingerprint()
        report["summary"]["ids16"]["pairs"].pop()
        self.assertEqual(self.export("fingerprint-ffi", report)["status"], "invalid")
        report = fingerprint()
        report["summary"]["ids16"]["pairs"][1]["pair"] = 1
        self.assertEqual(self.export("fingerprint-ffi", report)["status"], "invalid")
        report = fingerprint()
        del report["summary"]["ids16"]
        self.assertEqual(self.export("fingerprint-ffi", report)["status"], "partial")

    def test_fingerprint_marginal_p99_and_cold_regression_are_preserved(self):
        report = fingerprint(1000)
        report["summary"]["ids16"]["cold_metrics"].update(baseline_median_ns=1, candidate_median_ns=4,
            paired_median_ratio=0.25, median_ratio_ci95=[0.2, 0.4], median_decision="regression")
        for pair in report["summary"]["ids16"]["pairs"]:
            pair.update(cold_baseline_ns=1, cold_candidate_ns=4)
        summary = self.export("fingerprint-ffi", report)
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(next(row for row in summary["rows"] if row["fixture"] == "ids16" and row["phase"] == "cold")["decision"], "regression")
        report["summary"]["xml10000-fromXml"]["cold_metrics"]["p99_ratio"] = 99
        self.assertEqual(self.export("fingerprint-ffi", report)["status"], "invalid")

    def test_cpu_host_results_are_separate_and_use_corrected_uncertainty(self):
        summary = self.export("cpu-snapshot", cpu_host())
        self.assertEqual(summary["status"], "measured")
        self.assertEqual(len(summary["rows"]), 4)
        self.assertEqual(sum(row["comparison"] == "exact_host_cli" for row in summary["rows"]), 1)
        self.assertEqual(summary["uncertainty_method"], "mixed_output_bootstrap")
        negative = cpu_host()
        negative['host_comparison']['summary'].update(gate_pass=False, p99_ratio_ci95=[1, 10])
        negative['host_comparison']['host_gate_pass'] = negative['host_gate_pass'] = False
        summary = self.export('cpu-snapshot', negative)
        self.assertEqual(summary['status'], 'measured_gate_failed')
        self.assertTrue(all(row['eligible'] for row in summary['rows']))
        self.assertFalse(next(row for row in summary['rows'] if row['fixture'] == 'host-live')['gate_passed'])
        self.assertTrue(any(row.get('gate_passed') for row in summary['rows']))

    def test_cpu_host_invalid_witness_duplicate_or_source_drift_remains_invalid(self):
        for mutation in ("invalid", "duplicate", "drift", "witness", "metric", "false-gate"):
            report = cpu_host()
            if mutation == "invalid":
                report["host_comparison"]["observations"][0]["valid"] = False
            elif mutation == "duplicate":
                report["host_comparison"]["observations"][1] = copy.deepcopy(report["host_comparison"]["observations"][0])
            elif mutation == 'drift':
                report["host_comparison"]["source_integrity_pass"] = False
            elif mutation == 'witness':
                report['host_comparison']['observations'][0]['validation']['valid'] = False
            elif mutation == 'metric':
                report['host_comparison']['summary']['baseline_median_ns'] = 999
            else:
                report['host_comparison']['summary']['p99_ratio_ci95'] = [1, 10]
            self.assertEqual(self.export("cpu-snapshot", report)["status"], "invalid")


if __name__ == "__main__":
    unittest.main()
