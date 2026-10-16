"""Fail-closed public evidence for bounded independent PHP reference projections."""
import copy
import importlib.util
import json
import math
import subprocess
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result
EXPORT = module('remaining_export', ROOT / 'scripts/benchmark_callers_summary.py')
PAGES = module('remaining_pages', ROOT / 'scripts/benchmark-pages.py')
OLD = module('remaining_old_fixture', Path(__file__).with_name('test_benchmark_callers_export.py'))
MODULES = {'metadata-modules': {
    'article_dates': ('dates-ordinary', 'dates-folded', 'dates-uncommon-duplicates', 'dates-uncommon-malformed'),
    'article_identity': ('identity-ordinary', 'identity-overview', 'identity-uncommon-conflicts', 'identity-uncommon-boundary'),
    'article_xref': ('xref-ordinary', 'xref-overview', 'xref-uncommon-duplicates', 'xref-uncommon-limits')},
    'archive-modules': {
    'iso_primary_copies': ('iso-copies-equal', 'iso-copies-different', 'iso-copies-uncommon-limit', 'iso-copies-uncommon-partial'),
    'rar5_copy_checksums': ('rar-copy-crc', 'rar-copy-blake', 'rar-copy-uncommon-ambiguous', 'rar-copy-uncommon-truncated'),
    'rar5_quick_open': ('rar-quick-open-match', 'rar-quick-open-conflict', 'rar-quick-open-uncommon-retention', 'rar-quick-open-uncommon-corrupt')}}
WRAPPER = 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'
SOURCES = {WRAPPER, 'composer.json', 'composer.lock', 'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock',
    'docker/app/nzb-yenc-ffi/src/lib.rs', 'scripts/benchmark-remaining-modules.php', 'scripts/benchmark-sample-planning.php',
    'scripts/benchmark-article-processing.php', 'scripts/benchmark-yenc.php', 'tests/Fixtures/SamplePlanningBenchmarkFixtures.php',
    'tests/Fixtures/RemainingMetadataBenchmarkFixtures.php', 'tests/Fixtures/RemainingArchiveBenchmarkFixtures.php'} | {
    'docker/app/nzb-yenc-ffi/src/' + key + '.rs' for group in MODULES.values() for key in group}
INSTRUMENTATION = {key: 'a' * 64 for key in SOURCES if key.startswith(('scripts/', 'tests/'))}


def marginal(n, q, value):
    weights = [math.exp(math.lgamma(n + 1) - math.lgamma(k + 1) - math.lgamma(n - k + 1)
               + k * math.log(1-q) + (n-k) * math.log(q)) for k in range(n+1)]
    cdf, limits = 0, []
    for threshold in (.05 / 48 / 2, 1 - .05 / 48 / 2):
        cdf = 0
        for k, weight in enumerate(weights):
            cdf += weight / sum(weights)
            if cdf >= threshold:
                limits.append(k)
                break
    low, high = n - limits[1], n - limits[0] + 1
    return dict(estimate=value, samples=n, confidence=1-.05/48, ranks=[low, high],
                interval=[value if low >= 1 else None, value if high <= n else None])


def fixture(kind, samples=3, mode='screen'):
    scope = ('bounded-reference-shared-projection-v1' if kind == 'metadata-modules' else
             'bounded independent PHP reference versus shared metadata projection; no full-parser equivalence or production PHP speedup')
    declarations = {name: dict(module=key, category='common' if i < 2 else 'uncommon', comparison_scope=('bounded-reference-date-clock-projection-v2' if kind == 'metadata-modules' and key == 'article_dates' else scope))
                    for key, names in MODULES[kind].items() for i, name in enumerate(names)}
    hashes = dict.fromkeys(SOURCES, 'a' * 64)
    snapshot = dict(root='/private/raw', files={WRAPPER: 'a' * 64})
    records = [dict(type='protocol', protocol='remaining-modules-v1', family=kind, mode=mode, samples_per_stratum=samples,
                    strata=declarations, instrumentation=INSTRUMENTATION, baseline_kind='independent_php_reference_projection', confidence_family_size=48),
               dict(type='setup', source=snapshot, build_source_identities=hashes, library=dict(sha256='d'*64, path='/private/raw/native.so'))]
    decisions = {}
    marginals = {(category, backend): marginal(samples, .99 if category == 'uncommon' else .5, value)
                 for category in ('common', 'uncommon') for backend, value in (('php', 6.), ('native', 2.))}
    for name, declaration in declarations.items():
        module_name, category = declaration['module'], declaration['category']
        records.append(dict(type='fixture', stratum=name, module=module_name, sha256='b'*64, path='/private/raw/input'))
        for pair in range(samples):
            for backend, elapsed in (('php', 6.), ('native', 2.)):
                engine = 'nntmux-rust-' + module_name.replace('_', '-') if kind == 'metadata-modules' else 'nntmux-rust-ffi'
                observations = {phase: dict(elapsed_ms=elapsed, correct=True, selected_library_cached=backend == 'native',
                    parser_engine=engine if backend == 'native' else None, projection_sha256='c'*64) for phase in ('cold', 'warmup', 'warm')}
                result = dict(status='ok', backend=backend, fixture_sha256='b'*64, library_sha256='d'*64 if backend == 'native' else None,
                    observations=observations, runtime_sources=dict(snapshot, classes={'App\\Services\\Releases\\Fingerprint\\NativeMetadataParser': WRAPPER}),
                    instrumentation=INSTRUMENTATION, projection=dict(sha256='c'*64, path='/private/raw/result'), full=dict(sha256='e'*64, path='/private/raw/result'))
                records.append(dict(type='observation', stratum=name, pair=pair, backend=backend, eligible=True,
                    child=dict(status='ok', stdout_truncated_for_json=False, result=result), command=['/private/raw/secret']))
            records.append(dict(type='pair', stratum=name, pair=pair, order=['php', 'native'], fixture_sha256='b'*64,
                                eligible=True, exact_serialized_projection_parity=True))
        php, native = marginals[category, 'php'], marginals[category, 'native']
        interval = [3. if php['interval'][0] is not None and native['interval'][1] is not None else None,
                    3. if php['interval'][1] is not None and native['interval'][0] is not None else None]
        qualifies = mode == 'qualification' and samples >= 1000 and interval[0] is not None
        decision = {phase: dict(status='qualified_projection' if qualifies else 'inconclusive', eligible_pairs=samples, invalid_pairs=0,
                    metric='p99_latency' if category == 'uncommon' else 'median_latency', php=copy.deepcopy(php), native=copy.deepcopy(native),
                    php_over_native=3., marginal_alpha=.05/48, simultaneous_95_percent_speedup_interval=interval, qualifies=qualifies,
                    evidence='screen_only_no_adoption' if mode == 'screen' else 'reference_projection_only_no_adoption') for phase in ('cold','warm')}
        decisions[name] = decision
        records.append(dict(type='stratum', stratum=name, module=module_name, category=category, decision=decision))
    records.append(dict(type='summary', status='measured', identities_stable=True, invalid_pairs=0, strata=decisions,
                        baseline_kind='independent_php_reference_projection', performance_gates_passed=mode=='qualification', adoption_qualified=False, acceptance_criteria_met=False))
    return records


class RemainingEvidenceTest(unittest.TestCase):
    export = OLD.CallerSummaryExportTest.export

    def test_actual_php_metadata_declarations_match_the_exporter_contract(self):
        result = subprocess.run(
            ['php', '-r', 'require "tests/Fixtures/RemainingMetadataBenchmarkFixtures.php"; '
             'echo json_encode(Tests\\Fixtures\\RemainingMetadataBenchmarkFixtures::strata(), JSON_THROW_ON_ERROR);'],
            cwd=ROOT, check=True, capture_output=True, text=True, timeout=10)
        actual = json.loads(result.stdout)
        self.assertEqual(actual, EXPORT.REMAINING_STRATA['metadata-modules'])
        records = fixture('metadata-modules')
        self.assertEqual(actual, records[0]['strata'])
        records[0]['strata'] = actual
        summary = self.export('metadata-modules', records)
        self.assertEqual(summary['status'], 'measured')
        self.assertEqual(len(summary['rows']), 24)

    def test_stale_date_scope_is_rejected_without_changing_other_module_scopes(self):
        records = fixture('metadata-modules')
        for name, declaration in records[0]['strata'].items():
            if declaration['module'] == 'article_dates':
                self.assertEqual(declaration['comparison_scope'], 'bounded-reference-date-clock-projection-v2')
                declaration['comparison_scope'] = 'bounded-reference-shared-projection-v1'
            else:
                self.assertEqual(declaration['comparison_scope'], 'bounded-reference-shared-projection-v1')
        summary = self.export('metadata-modules', records)
        self.assertEqual(summary['status'], 'invalid')
        self.assertTrue(all(not row['qualification_gate_passed'] and not row['eligible'] for row in summary['rows']))

    def test_both_fixed_families_export_24_projection_rows_privately(self):
        for kind in MODULES:
            summary = self.export(kind, fixture(kind))
            self.assertEqual(summary['status'], 'measured')
            self.assertEqual(summary['baseline_kind'], 'independent_php_reference_projection')
            self.assertEqual(summary['evidence_type'], 'shared_independent_php_reference_projection')
            self.assertEqual(len(summary['rows']), 24)
            self.assertTrue(all(row['eligible'] and row['confidence_family_size'] == 48 for row in summary['rows']))
            self.assertNotIn('/private/raw', json.dumps(summary))
            PAGES.validate_caller_rows(summary, kind)

    def test_tail_is_hidden_and_insufficient_samples_never_qualify(self):
        for kind in MODULES:
            summary = self.export(kind, fixture(kind))
            for row in summary['rows']:
                if row['category'] == 'uncommon':
                    self.assertEqual(row['metric'], 'p99_unavailable')
                    self.assertEqual(row['speedup_interval'], [None, None])
                    self.assertIsNone(row['baseline_latency_interval_ms'])
                    self.assertNotIn('baseline_over_candidate', row)
                self.assertFalse(row['qualification_gate_passed'])

    def test_bad_source_observations_marginals_flags_and_global_integrity_disqualify_all(self):
        mutations = [lambda rows: rows[0].update(family='wrong'),
            lambda rows: rows[0].update(confidence_family_size=24),
            lambda rows: rows[0]['strata']['dates-ordinary' if rows[0]['family'] == 'metadata-modules' else 'iso-copies-equal'].update(category='uncommon'),
            lambda rows: rows.append(dict(type='unexpected', payload='/private/raw')),
            lambda rows: rows.insert(-1, copy.deepcopy(next(r for r in rows if r['type']=='pair'))),
            lambda rows: rows[-1]['strata'].pop(next(iter(rows[-1]['strata']))),
            lambda rows: rows[1]['build_source_identities'].pop(WRAPPER),
            lambda rows: rows[-1].update(identities_stable=False),
            lambda rows: rows[-1].update(performance_gates_passed=True),
            lambda rows: next(r for r in rows if r['type']=='observation')['child']['result']['runtime_sources']['files'].update({WRAPPER:'f'*64}),
            lambda rows: next(r for r in rows if r['type']=='observation' and r['backend']=='native')['child']['result']['observations']['warmup'].update(parser_engine='fallback'),
            lambda rows: next(r for r in rows if r['type']=='observation' and r['backend']=='native')['child']['result']['observations']['cold'].update(selected_library_cached=False),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold']['php'].update(estimate=7),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold']['php'].update(ranks=[1,3]),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold'].update(qualifies=True),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold'].update(simultaneous_95_percent_speedup_interval=[2,4]),
            lambda rows: next(r for r in rows if r['type']=='pair').update(eligible=False),
            lambda rows: next(r for r in rows if r['type']=='observation' and r['backend']=='php')['child']['result']['observations']['cold'].update(selected_library_cached=True),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold']['php'].update(confidence=.95),
            lambda rows: next(r for r in rows if r['type']=='stratum')['decision']['cold'].update(php_over_native=10),
            lambda rows: rows.pop(next(i for i,r in enumerate(rows) if r['type']=='observation'))]
        for kind in MODULES:
            for mutation in mutations:
                rows = fixture(kind)
                mutation(rows)
                summary = self.export(kind, rows)
                self.assertIn(summary['status'], ('invalid','partial'))
                self.assertFalse(any(r['eligible'] or r.get('qualification_gate_passed') for r in summary['rows']))
            for options in ({'trailing':'broken\n'}, {'resources':'forged'}, {'runtime':'mismatch'}, {'runtime':'missing'}):
                summary = self.export(kind, fixture(kind), **options)
                self.assertFalse(any(r['eligible'] or r.get('qualification_gate_passed') for r in summary['rows']))

    def test_qualified_projection_stays_no_adoption_and_pages_rejects_forged_claims(self):
        summary = self.export('metadata-modules', fixture('metadata-modules', 1000, 'qualification'))
        self.assertEqual(summary['status'], 'measured')
        self.assertTrue(all(r['qualification_gate_passed'] for r in summary['rows']))
        PAGES.validate_caller_rows(summary, 'metadata-modules')
        for mutation in (lambda s: s['rows'][0].update(module='rar5_quick_open'),
                         lambda s: s['rows'][0].update(speedup_interval=[2,4]),
                         lambda s: s['rows'][0].update(qualification_gate_passed=False),
                         lambda s: s.update(baseline_kind='historical_php'),
                         lambda s: s.update(baseline_revision='f'*40),
                         lambda s: s['rows'][0].update(samples=1001, correctness_observations=2002, native_proof_observations=1001),
                         lambda s: s['source_sha256'].pop(WRAPPER)):
            changed = copy.deepcopy(summary)
            mutation(changed)
            with self.assertRaises(ValueError):
                PAGES.validate_caller_rows(changed, 'metadata-modules')
        run = dict(head_sha='e'*40, id=123, run_attempt=1)
        PAGES.validate_summary(summary, run, 'metadata-modules', 'ubuntu-24.04-arm')

    def test_missing_terminal_or_stratum_and_999_tail_fail_closed(self):
        for kind in MODULES:
            rows = fixture(kind)
            for partial in (rows[:-1], [r for r in rows if r.get('type') != 'stratum' or r.get('stratum') != next(iter(rows[0]['strata']))]):
                summary = self.export(kind, partial)
                self.assertNotEqual(summary['status'], 'measured')
                self.assertFalse(any(r['eligible'] or r.get('qualification_gate_passed') for r in summary['rows']))
        summary = self.export('archive-modules', fixture('archive-modules', 999))
        self.assertEqual(summary['status'], 'measured')
        for row in summary['rows']:
            if row['category'] == 'uncommon':
                self.assertEqual(row['metric'], 'p99_unavailable')
                self.assertNotIn('baseline_over_candidate', row)
                self.assertEqual(row['speedup_interval'], [None, None])
                self.assertIsNone(row['candidate_latency_interval_ms'])
        PAGES.validate_caller_rows(summary, 'archive-modules')

    def test_missing_raw_report_publishes_only_fixed_scope(self):
        import os
        import tempfile
        with tempfile.TemporaryDirectory() as temporary:
            previous = Path.cwd()
            try:
                os.chdir(temporary)
                for kind in MODULES:
                    summary = EXPORT.export(kind, 'ubuntu-latest')
                    self.assertEqual(summary['status'], 'missing')
                    self.assertFalse(summary['rows'])
                    PAGES.validate_caller_rows(summary, kind)
            finally:
                os.chdir(previous)

    def test_workflow_and_inventory_cover_six_modules_with_explicit_gaps(self):
        expected = PAGES.WORKFLOWS['archive-sample-performance.yml']
        for kind in MODULES:
            for runner in ('ubuntu-latest','ubuntu-24.04-arm'):
                self.assertIn((kind,runner), expected)
        coverage = {Path(row['target']).stem: row for row in PAGES.coverage(ROOT)}
        for group in MODULES.values():
            for name in group:
                self.assertIn('reference', coverage[name]['coverage'].lower())
                self.assertIn('richer', coverage[name]['detail'].lower())
        self.assertIn('No automated', coverage['zstd_frame']['coverage'])

if __name__ == '__main__':
    unittest.main()
