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
PAGES_SPEC = importlib.util.spec_from_file_location('caller_pages', SCRIPT.with_name('benchmark-pages.py'))
PAGES = importlib.util.module_from_spec(PAGES_SPEC)
PAGES_SPEC.loader.exec_module(PAGES)
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


VENDOR = dict.fromkeys(('rar4-stored', 'rar5-mixed', 'zip-stored', 'seven-stored'), 'common') | dict.fromkeys(
    ('rar4-solid', 'rar5-encrypted-headers', 'zip-aes', 'seven-encrypted-headers'), 'uncommon')
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
VENDOR_FILES = {'composer.lock', 'vendor/composer/installed.json', 'vendor/dariusiii/rarinfo/composer.json',
                'vendor/dariusiii/rarinfo/ArchiveReader.php', 'vendor/dariusiii/rarinfo/RarInfo.php',
                'vendor/dariusiii/rarinfo/ZipInfo.php', 'vendor/dariusiii/rarinfo/SzipInfo.php',
                'scripts/benchmark-vendor-archive.php', 'tests/Fixtures/VendorArchiveBenchmarkFixtures.php'}


def fixture(kind, samples=31, mode="screen"):
    definitions = VENDOR if kind == "vendor-archive" else SAMPLE if kind == "sample-planning" else ARCHIVE
    records = [{"type": "protocol", "protocol": kind + "-v1", "mode": mode,
                "samples_per_stratum": samples, "instrumentation": {MARKER: "a" * 64}},
               {"type": "setup", "build_source_identities": {"composer.lock": "b" * 64, MARKER: "c" * 64},
                "library": {"sha256": "d" * 64, "path": MARKER}}]
    if kind == 'vendor-archive':
        records[0]['strata'] = {name: dict(category=category, path=VENDOR_INPUTS[name][0], sha256=VENDOR_INPUTS[name][1], paired=True)
                                for name, category in VENDOR.items()}
        hashes = dict.fromkeys(VENDOR_FILES, 'a' * 64) | dict(VENDOR_INPUTS.values())
        records[1]['build_source_identities'].update(hashes)
        records[1]['vendor_receipt'] = dict(package='dariusiii/rarinfo', version='v3.1', license=['BSD-3-Clause'],
            source_reference='2cd3220b76d807de1798a98d743f20224f30d63a',
            files={key: value for key, value in hashes.items() if key.startswith('vendor/') or key == 'composer.lock'})
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
                if kind == 'vendor-archive':
                    row['marginal_alpha'] = 0.05 / 32
                    row['php']['confidence'] = row['native']['confidence'] = 1 - 0.05 / 32
                    if mode == 'qualification' and size >= 1000:
                        row.update(qualifies=True, status='qualified')
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
    if kind == 'vendor-archive':
        records[-1]['vendor_archive_shared_projection_qualified'] = mode == 'qualification' and samples >= 1000
    return records


class CallerSummaryExportTest(unittest.TestCase):
    def export(self, kind, records, resources=None, runtime='valid', trailing=''):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "utility-evidence" / kind / "observations.jsonl"
            source.parent.mkdir(parents=True)
            source.write_text("".join(json.dumps(row) + "\n" for row in records) + trailing)
            UTILITY_FIXTURE.write_resources(root, resources)
            if runtime != 'missing':
                (root / "utility-evidence/runtime.json").write_text(json.dumps({
                    "executed_sha": ("f" if runtime == 'mismatch' else "e") * 40,
                    "architecture": "aarch64", "php": "8.5", MARKER: MARKER}))
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

    def test_global_integrity_failure_disqualifies_every_row_and_gate(self):
        for kind in ('sample-planning', 'archive-parsing', 'vendor-archive'):
            records = fixture(kind, samples=1000, mode='qualification')
            for record in records:
                if record.get('type') == 'stratum' and record['category'] in ('common', 'uncommon'):
                    for decision in record['decision'].values():
                        decision.update(status='qualified', qualifies=True, evidence='qualification')
            if kind == 'sample-planning':
                records[-1].update(status='qualified', acceptance_criteria_met=True)
            elif kind == 'archive-parsing':
                records[-1]['par2_shared_projection_qualified'] = True
            valid = self.export(kind, records)
            self.assertEqual(valid['status'], 'measured')
            self.assertTrue(all(row['eligible'] for row in valid['rows']))
            self.assertTrue(any(row.get('qualification_gate_passed') for row in valid['rows']))
            for failure in ('source-drift', 'malformed', 'runtime-mismatch', 'runtime-missing'):
                with self.subTest(kind=kind, failure=failure):
                    mutated = copy.deepcopy(records)
                    options = {}
                    if failure == 'source-drift':
                        mutated[-1].update(identities_stable=False, status='invalid')
                    elif failure == 'malformed':
                        options['trailing'] = 'not-json\n'
                    else:
                        options['runtime'] = 'mismatch' if failure == 'runtime-mismatch' else 'missing'
                    summary = self.export(kind, mutated, **options)
                    self.assertEqual(summary['status'], 'partial' if failure == 'runtime-missing' else 'invalid')
                    self.assertFalse(any(row['eligible'] for row in summary['rows']))
                    self.assertFalse(any(row.get('qualification_gate_passed') for row in summary['rows']))
                    # Keep valid numeric observations available for diagnosis.
                    self.assertTrue(any(row.get('baseline_over_candidate') == 2.5 for row in summary['rows']))

    def test_finite_tail_bounds_below_minimum_sample_count_stay_unavailable(self):
        for kind in ('sample-planning', 'archive-parsing', 'vendor-archive'):
            with self.subTest(kind=kind):
                records = fixture(kind, samples=999)
                # A finite order-statistic interval is possible below the
                # protocol's 1,000-pair reporting minimum.
                for record in records:
                    if record.get('type') == 'stratum' and record['category'] == 'uncommon':
                        for decision in record['decision'].values():
                            decision['php']['interval'] = [4.0, 6.0]
                            decision['native']['interval'] = [1.5, 2.5]
                            decision['simultaneous_95_percent_speedup_interval'] = [1.6, 4.0]
                summary = self.export(kind, records)
                self.assertEqual(summary['status'], 'measured')
                tails = [row for row in summary['rows'] if row['category'] == 'uncommon']
                self.assertTrue(tails)
                for row in tails:
                    self.assertEqual(row['metric'], 'p99_unavailable')
                    self.assertEqual(row['speedup_interval'], [None, None])
                    self.assertFalse(row['qualification_gate_passed'])
                    self.assertTrue(row['eligible'])
                    self.assertNotIn('baseline_over_candidate', row)
                PAGES.validate_caller_rows(summary, kind)

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

    def test_vendor_archive_projects_8_fixed_strata_32_marginals_without_adoption(self):
        summary = self.export('vendor-archive', fixture('vendor-archive'))
        self.assertEqual(summary['status'], 'measured')
        self.assertEqual(summary['evidence_type'], 'shared_vendor_archive_header_projection')
        self.assertEqual(len(summary['rows']), 16)
        self.assertEqual({row['fixture'] for row in summary['rows']}, set(VENDOR))
        self.assertTrue(all(row['comparison'] == 'shared_vendor_header_projection' and row['confidence_family_size'] == 32 for row in summary['rows']))
        self.assertEqual(sum(row['metric'] == 'p99_unavailable' for row in summary['rows']), 8)
        self.assertFalse(any(row.get('qualification_gate_passed') for row in summary['rows']))
        self.assertTrue(VENDOR_FILES <= set(summary['source_sha256']))
        for path, expected in VENDOR_INPUTS.values():
            self.assertEqual(summary['source_sha256'][path], expected)
        qualified = self.export('vendor-archive', fixture('vendor-archive', samples=1000, mode='qualification'))
        self.assertEqual(qualified['status'], 'measured')
        self.assertTrue(all(row['qualification_gate_passed'] for row in qualified['rows']))
        self.assertIs(qualified['adoption_qualified'], False)

    def test_vendor_archive_wrong_protocol_confidence_receipt_or_locked_input_is_invalid(self):
        mutations = (
            lambda rows: rows[0].update(protocol='archive-parsing-v1'),
            lambda rows: rows[0]['strata']['rar4-stored'].update(sha256='a' * 64),
            lambda rows: rows[1]['vendor_receipt'].update(license=['private']),
            lambda rows: rows[1]['vendor_receipt'].update(source_reference='a' * 40),
            lambda rows: rows[1]['vendor_receipt']['files'].pop('vendor/dariusiii/rarinfo/RarInfo.php'),
            lambda rows: rows[1]['build_source_identities'].pop('vendor/dariusiii/rarinfo/ZipInfo.php'),
            lambda rows: next(row for row in rows if row['type'] == 'pair').update(shared_projection_parity=False),
            lambda rows: next(row for row in rows if row['type'] == 'stratum')['decision']['cold'].update(marginal_alpha=.05/16),
            lambda rows: next(row for row in rows if row['type'] == 'stratum')['decision']['cold']['php'].update(confidence=.95),
            lambda rows: next(row for row in rows if row['type'] == 'stratum')['decision']['cold'].update(simultaneous_95_percent_speedup_interval=[2, 4]),
            lambda rows: rows[-1].update(acceptance_criteria_met=True),
            lambda rows: rows[-1].update(vendor_archive_shared_projection_qualified=True),
        )
        for mutation in mutations:
            rows = fixture('vendor-archive')
            mutation(rows)
            with self.subTest(mutation=mutation):
                self.assertEqual(self.export('vendor-archive', rows)['status'], 'invalid')
        for mutation in ('missing', 'changed', 'forged'):
            result = self.export('vendor-archive', fixture('vendor-archive'), resources=mutation)
            self.assertEqual(result['status'], 'invalid')
            self.assertFalse(any(row['eligible'] for row in result['rows']))

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
        for kind in ("sample-planning", "archive-parsing", "vendor-archive"):
            missing_stratum = fixture(kind)
            del missing_stratum[next(index for index, row in enumerate(missing_stratum) if row["type"] == "stratum")]
            for records in (fixture(kind)[:-1], missing_stratum):
                summary = self.export(kind, records)
                self.assertEqual(summary["status"], "partial")
                self.assertFalse(any(row['eligible'] for row in summary['rows']))
                self.assertFalse(any(row.get('qualification_gate_passed') for row in summary['rows']))

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
