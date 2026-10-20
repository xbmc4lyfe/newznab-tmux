"""Identity report publication preserves provenance, routing and uncertainty scope."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


EXPORT = module('identity', 'scripts/export-identity-benchmark-summary.py')
PAGES = module('identity_pages', 'scripts/benchmark-pages.py')
RESOURCES = module('identity_resources_fixture', 'scripts/benchmark-resource-limits.py')
SHA = 'a' * 40
ENV = dict(GITHUB_SHA=SHA, GITHUB_REPOSITORY=PAGES.REPOSITORY, GITHUB_RUN_ID='12', GITHUB_RUN_ATTEMPT='2')
MARKER = 'private-unpublished-payload'


def fixture(kind):
    report = dict(protocol=dict(name=kind + '-v1', batch_size=16, threshold=1.5), status='qualified',
        environment=dict(source_sha=SHA, source_commit=SHA, library_sha256='b' * 64,
            source_sha256=dict.fromkeys(EXPORT.SOURCES, 'c' * 64), private=MARKER), strata={}, raw=[MARKER])
    if kind == 'filename-identity':
        block = dict(php=dict(estimate=2000, low=1900, high=2100, samples=2000),
            rust=dict(estimate=1000, low=900, high=1100, samples=2000), speedup=2,
            speedup_lower=1.7, speedup_upper=2.3)
        for name, category in EXPORT.FILENAME.items():
            report['strata'][name] = dict(category=category, parity_mismatches=0, observations=2000,
                median=copy.deepcopy(block), p99=copy.deepcopy(block), description=MARKER)
        report['batch'] = dict(observations=2000, median=copy.deepcopy(block), p99=copy.deepcopy(block))
    else:
        report['environment'].pop('source_sha256')
        report.update(cold={}, startup_bounded_p99={}, startup=dict(runs=200, p99_ms=0.3, raw=[MARKER]))
        for name, category in EXPORT.ARTICLE.items():
            report['strata'][name] = dict(category=category, statistic='p99' if category == 'uncommon' else 'median',
                samples=1000, speedup=2, speedup_ci95=[1.7, 2.3], php_ms=2, rust_ms=1, declined=category == 'routed',
                raw=[MARKER], description=MARKER)
            if name != 'large-range':
                report['cold'][name] = dict(samples=41, speedup=2, speedup_ci95=[1.7, 2.3], php_ms=2,
                    rust_ms=1, used_rust=category != 'routed', rust_runs=0 if category == 'routed' else 41, raw=[MARKER])
            if category == 'uncommon':
                report['startup_bounded_p99'][name] = dict(speedup=2, speedup_ci95=[1.7, 2.3])
    return report


class IdentitySummaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.evidence = self.root / 'filename-identity-evidence'
        self.evidence.mkdir()

    def save(self, kind, report):
        path = self.evidence / ('benchmark/summary.json' if kind == 'filename-identity' else 'per-article-obfuscation/report.json')
        path.parent.mkdir(exist_ok=True)
        path.write_text(json.dumps(report))
        return path

    def export(self, kind, report=None):
        if report is not None:
            self.save(kind, report)
        return EXPORT.export(kind, 'ubuntu-latest', self.root, ENV)

    def validate(self, result):
        PAGES.validate_summary(result, dict(head_sha=SHA, id=12, run_attempt=2), result['kind'], 'ubuntu-latest')

    def receipts(self, kind, report):
        snapshot = dict(executed_sha=SHA, source_sha256=dict.fromkeys(EXPORT.SOURCE_PATHS, 'c' * 64), library_sha256='b' * 64)
        for phase in ('before', 'after'):
            (self.evidence / f'measurement-{phase}.json').write_text(json.dumps(snapshot))
        (self.evidence / 'runtime.json').write_text(json.dumps(dict(executed_sha=SHA, rustc='rustc fixture',
            cargo='cargo fixture', architecture='x86_64', runner_image_version='fixture', build_profile='release')))
        resource = dict(schema_version=1, valid=True, scope=RESOURCES.SCOPE, outer_host_limits='unknown', captured_at_unix_ns=1,
            process_limits='fixture', shell_soft_limits='fixture', shell_hard_limits='fixture', cgroup_membership='fixture',
            mountinfo='fixture', cpu_affinity=[0], cgroup_v2_ancestors=[dict(cgroup='/',
                limits=dict.fromkeys(RESOURCES.STATIC_FIELDS, 'max'), usage=dict.fromkeys(RESOURCES.DYNAMIC_FIELDS, '0'))])
        left, right = [self.evidence / f'resource-limits-{phase}.json' for phase in ('before', 'after')]
        for path in (left, right):
            path.write_text(json.dumps(resource))
        (self.evidence / 'resource-limits-comparison.json').write_text(json.dumps(RESOURCES.compare(left, right)))

    def test_both_reports_publish_bounded_descriptive_metrics_without_raw_payloads(self):
        for kind, expected in (('filename-identity', 16), ('per-article-obfuscation', 50)):
            with self.subTest(kind=kind):
                result = self.export(kind, fixture(kind))
                self.validate(result)
                self.assertEqual('incomplete_provenance', result['status'])
                self.assertEqual(expected, len(result['rows']))
                self.assertFalse(result['adoption_qualified'])
                self.assertTrue(all(row['eligible'] is False for row in result['rows']))
                self.assertNotIn(MARKER, json.dumps(result))
                self.assertLess(len(json.dumps(result)), 100000)
                self.assertIn('report_sha256', result)
                self.assertIn('protocol_sha256', result)

    def test_frozen_new_run_receipts_enable_verified_metrics_without_adoption(self):
        for kind in EXPORT.KINDS:
            report = fixture(kind)
            self.receipts(kind, report)
            result = self.export(kind, report)
            self.validate(result)
            self.assertEqual('recorded', result['status'])
            self.assertEqual('verified_current', result['identity_provenance_status'])
            self.assertTrue(all(row['eligible'] == (row['phase'] != 'startup-composition') for row in result['rows']))
            self.assertFalse(result['adoption_qualified'])
            self.assertEqual(EXPORT.SOURCE_PATHS | EXPORT.RESOURCE_KEYS, set(result['source_sha256']))

    def test_source_library_runtime_and_resource_drift_fail_closed(self):
        mutations = [
            ('measurement-after.json', lambda value: value.update(library_sha256='d' * 64)),
            ('measurement-after.json', lambda value: value['source_sha256'].update({'composer.lock': 'd' * 64})),
            ('measurement-after.json', lambda value: value['source_sha256'].update({'app/Services/Binaries/PerArticleObfuscationRouter.php': 'd' * 64})),
            ('runtime.json', lambda value: value.update(executed_sha='d' * 40)),
            ('resource-limits-after.json', lambda value: value.update(cpu_affinity=[1])),
        ]
        for filename, mutate in mutations:
            report = fixture('filename-identity')
            self.receipts('filename-identity', report)
            path = self.evidence / filename
            value = json.loads(path.read_text())
            mutate(value)
            path.write_text(json.dumps(value))
            result = self.export('filename-identity', report)
            self.validate(result)
            self.assertEqual('invalid', result['status'], filename)
            self.assertEqual([], result['rows'])

    def test_missing_partial_invalid_and_malformed_reports_stay_visible(self):
        for kind in EXPORT.KINDS:
            result = self.export(kind)
            self.validate(result)
            self.assertEqual('missing', result['status'])
            for report in ({}, dict(status='invalid'), fixture(kind) | {'strata': {}}):
                result = self.export(kind, report)
                self.validate(result)
                self.assertEqual('invalid', result['status'])
                self.assertEqual([], result['rows'])
            path = self.save(kind, {})
            for raw in ('{', '{"protocol":1,"protocol":2}', '{"protocol":NaN}', '{"protocol":1e999}'):
                path.write_text(raw)
                self.assertEqual('invalid', self.export(kind)['status'])
            path.unlink()

    def test_producer_gate_status_is_preserved_without_adoption_claim(self):
        for kind, statuses in (('filename-identity', ('qualified', 'not_qualified')),
                               ('per-article-obfuscation', ('qualified', 'not-qualified', 'inconclusive'))):
            for status in statuses:
                result = self.export(kind, fixture(kind) | {'status': status})
                self.validate(result)
                self.assertEqual(status, result['benchmark_status'])
                self.assertFalse(result['adoption_qualified'])

    def test_partial_matrix_parity_bad_samples_or_invalid_intervals_rejected(self):
        for kind in EXPORT.KINDS:
            for change in ('missing', 'category', 'parity', 'samples', 'interval', 'source'):
                report = fixture(kind)
                name = next(iter(report['strata']))
                value = report['strata'][name]
                if change == 'missing':
                    report['strata'].pop(name)
                elif change == 'category':
                    value['category'] = MARKER
                elif change == 'parity':
                    value['parity_mismatches' if kind == 'filename-identity' else 'parity'] = 1
                elif change == 'samples':
                    value['observations' if kind == 'filename-identity' else 'samples'] = True
                elif change == 'interval':
                    if kind == 'filename-identity':
                        value['median']['speedup_lower'] = 9
                    else:
                        value['speedup_ci95'] = [9, 1]
                else:
                    report['environment']['source_sha' if kind == 'filename-identity' else 'source_commit'] = 'd' * 40
                result = self.export(kind, report)
                self.assertEqual('invalid', result['status'], (kind, change))
                self.assertEqual([], result['rows'])

    def test_receipts_reject_duplicate_keys_nonfinite_and_oversize(self):
        for filename in ('measurement-before.json', 'runtime.json', 'resource-limits-before.json'):
            for raw in ('{"a":1,"a":2}', '{"a":NaN}', ' ' * (1024 * 1024 + 1)):
                report = fixture('filename-identity')
                self.receipts('filename-identity', report)
                (self.evidence / filename).write_text(raw)
                self.assertEqual('invalid', self.export('filename-identity', report)['status'])

    def test_routing_and_diagnostic_intervals_are_visible_in_renderer(self):
        result = self.export('per-article-obfuscation', fixture('per-article-obfuscation'))
        warm = next(row for row in result['rows'] if row['fixture'] == 'readable-yenc' and row['phase'] == 'warm')
        self.assertEqual('same_php_control', warm['comparison'])
        self.assertEqual('php', warm['candidate_backend'])
        cold = next(row for row in result['rows'] if row['fixture'] == 'readable-yenc' and row['phase'] == 'cold')
        self.assertFalse(cold['used_rust'])
        self.assertEqual(0, cold['rust_runs'])
        rendered = PAGES.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[result]))
        for phrase in ('Producer status: qualified', 'incomplete', 'pointwise paired bootstrap', 'Descriptive / pointwise', 'PHP control'):
            self.assertIn(phrase, rendered)
        self.assertNotIn('<th>Simultaneous decision bounds</th>', rendered)
        self.assertNotIn(MARKER, rendered)

    def test_public_validator_rejects_private_extra_fields_routing_and_adoption(self):
        base = self.export('per-article-obfuscation', fixture('per-article-obfuscation'))
        for change in ('extra', 'raw', 'routing', 'adoption', 'interval', 'missing', 'duplicate'):
            result = copy.deepcopy(base)
            if change == 'extra':
                result['private'] = MARKER
            elif change == 'raw':
                result['rows'][0]['raw'] = [MARKER]
            elif change == 'routing':
                next(row for row in result['rows'] if row['phase'] == 'cold')['rust_runs'] = 0
            elif change == 'adoption':
                result['adoption_qualified'] = True
            elif change == 'interval':
                result['rows'][0]['diagnostic_speedup_interval'] = [3, 1]
            elif change == 'missing':
                result['rows'].pop()
            else:
                result['rows'].append(copy.deepcopy(result['rows'][0]))
            with self.assertRaises(ValueError, msg=change):
                self.validate(result)

    def test_both_architectures_collect_only_exact_current_attempt_public_artifacts(self):
        summaries = {}
        for kind in EXPORT.KINDS:
            summary = self.export(kind, fixture(kind))
            for runner in ('ubuntu-latest', 'ubuntu-24.04-arm'):
                summaries[(kind, runner)] = summary | {'runner': runner}
        artifacts, archives = [], {}
        for number, ((kind, runner), summary) in enumerate(summaries.items(), 1):
            data = io.BytesIO()
            with zipfile.ZipFile(data, 'w') as archive:
                archive.writestr('summary.json', json.dumps(summary))
            archives[number] = data.getvalue()
            artifacts.append(dict(id=number, name=f'public-benchmark-{kind}-{runner}-12-2',
                expired=False, size_in_bytes=len(archives[number])))
        run = dict(id=12, run_attempt=2, head_sha=SHA, event='push', head_branch='master',
            head_repository=dict(full_name=PAGES.REPOSITORY), status='completed', conclusion='failure')
        def fetch(path):
            return {'artifacts': artifacts} if '/artifacts?' in path else {'workflow_runs': [run]}
        expected = list(summaries)
        with patch.object(PAGES, 'WORKFLOWS', {'archive-sample-performance.yml': expected}), \
                patch.object(PAGES, 'last_valid_comparison', return_value=None), \
                patch.object(PAGES, 'collect_dispatches', return_value=([], [])):
            report = PAGES.collect(SHA, ROOT, fetch, archives.__getitem__)
            self.assertEqual(4, len(report['results']))
            self.assertTrue(all(row['status'] == 'incomplete_provenance' for row in report['results']))
            self.assertTrue(all(row['workflow_status'] == 'failure' for row in report['results']))
            for summary in summaries.values():
                summary['run_attempt'] = 1
                with self.assertRaises(ValueError):
                    PAGES.validate_summary(summary, run, summary['kind'], summary['runner'])

    def test_percentile_observations_below_thousand_are_not_published(self):
        report = fixture('filename-identity')
        for value in report['strata'].values():
            value['observations'] = 999
            for metric in ('median', 'p99'):
                value[metric]['php']['samples'] = value[metric]['rust']['samples'] = 999
        self.assertEqual('invalid', self.export('filename-identity', report)['status'])
        report = fixture('per-article-obfuscation')
        report['strata']['large-range']['samples'] = 999
        self.assertEqual('invalid', self.export('per-article-obfuscation', report)['status'])

    def test_failure_and_incomplete_envelopes_reject_irrelevant_nested_payloads(self):
        for kind in EXPORT.KINDS:
            examples = [self.export(kind), self.export(kind, {}), self.export(kind, fixture(kind))]
            self.receipts(kind, fixture(kind))
            examples.append(self.export(kind, fixture(kind)))
            self.assertEqual('recorded', examples[-1]['status'])
            for base in examples:
                for field, payload in (
                    ('runtime_identity', {'raw_private_payload': MARKER}),
                    ('identity_manifest_sha256', {'private': MARKER}),
                    ('baseline_revision', MARKER), ('uncertainty_method', 'not_reported'),
                    ('source_and_library_identities_stable', MARKER),
                ):
                    with self.subTest(kind=kind, status=base['status'], field=field):
                        with self.assertRaises(ValueError):
                            self.validate(base | {field: payload})

    def test_observed_warm_decline_controls_backend_even_for_common_fixture(self):
        report = fixture('per-article-obfuscation')
        report['strata']['real-uploads']['declined'] = True
        result = self.export('per-article-obfuscation', report)
        self.validate(result)
        observed = next(row for row in result['rows'] if row['fixture'] == 'real-uploads' and row['phase'] == 'warm')
        self.assertEqual('php', observed['candidate_backend'])
        self.assertEqual('same_php_control', observed['comparison'])

    def test_executed_router_is_in_frozen_source_contract(self):
        self.assertIn('app/Services/Binaries/PerArticleObfuscationRouter.php', EXPORT.SOURCE_PATHS)

    def test_identity_tests_are_wired_to_pages_ci_and_pull_request_trigger(self):
        workflow = (ROOT / '.github/workflows/benchmark-pages.yml').read_text()
        self.assertIn("- 'tests/Unit/test_identity_benchmark_summary.py'", workflow)
        self.assertIn('python3 -B tests/Unit/test_identity_benchmark_summary.py', workflow)
        producer = (ROOT / '.github/workflows/archive-sample-performance.yml').read_text()
        self.assertIn("- 'tests/Unit/test_identity_benchmark_summary.py'", producer)
        self.assertIn('python3 -B tests/Unit/test_identity_benchmark_summary.py', producer)

    def test_actual_collector_contains_oversized_integer_metrics(self):
        base = self.export('per-article-obfuscation', fixture('per-article-obfuscation'))
        run = dict(id=12, run_attempt=2, head_sha=SHA, event='push', head_branch='master',
            head_repository=dict(full_name=PAGES.REPOSITORY), status='completed', conclusion='failure')
        expected = [('per-article-obfuscation', 'ubuntu-latest')]
        for field in ('baseline_over_candidate', 'baseline_latency_ms', 'diagnostic_speedup_interval'):
            with self.subTest(field=field):
                summary = copy.deepcopy(base)
                summary['rows'][0][field] = [1, 10**400] if field == 'diagnostic_speedup_interval' else 10**400
                data = io.BytesIO()
                with zipfile.ZipFile(data, 'w') as archive:
                    archive.writestr('summary.json', json.dumps(summary))
                raw = data.getvalue()
                artifact = dict(id=1, expired=False, size_in_bytes=len(raw),
                    name='public-benchmark-per-article-obfuscation-ubuntu-latest-12-2')
                def fetch(path):
                    return {'artifacts': [artifact]} if '/artifacts?' in path else {'workflow_runs': [run]}
                with patch.object(PAGES, 'WORKFLOWS', {'archive-sample-performance.yml': expected}), \
                        patch.object(PAGES, 'last_valid_comparison', return_value=None), \
                        patch.object(PAGES, 'collect_dispatches', return_value=([], [])):
                    report = PAGES.collect(SHA, ROOT, fetch, lambda artifact_id: raw)
                self.assertEqual('Summary invalid or unavailable', report['results'][0]['status'])
                self.assertEqual([], report['results'][0]['rows'])

    def test_startup_composition_retains_actual_component_counts_and_scope(self):
        report = fixture('per-article-obfuscation')
        report['startup'].update(runs=200, p99_ms=0.3)
        result = self.export('per-article-obfuscation', report)
        rows = [row for row in result['rows'] if row['phase'] == 'startup-composition']
        self.assertEqual(3, len(rows))
        for row in rows:
            self.assertEqual('composed_quantile_ratio', row['metric'])
            self.assertEqual(1000, row['warm_samples'])
            self.assertEqual(200, row['startup_samples'])
            self.assertEqual(0.3, row['startup_p99_ms'])
            self.assertNotIn('samples', row)
        rendered = PAGES.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[result]))
        for phrase in ('Warm component N=1000', 'Startup component N=200', 'PHP warm q99 / (Rust warm q99 + startup q99)',
                'not empirical whole-caller p99', 'guaranteed 99th-percentile bound'):
            self.assertIn(phrase, rendered)
        self.assertFalse(any(row['eligible'] for row in rows))
        for field, value in (('samples', 1000), ('warm_samples', 999), ('startup_samples', True),
                ('startup_samples', 0), ('startup_samples', 201), ('warm_samples', 1001),
                ('startup_p99_ms', 0.4), ('warm_php_p99_ms', 9), ('metric', 'p99_latency'), ('eligible', True)):
            malformed = copy.deepcopy(result)
            next(row for row in malformed['rows'] if row['phase'] == 'startup-composition')[field] = value
            with self.assertRaises(ValueError, msg=field):
                self.validate(malformed)
        for fixture_name in EXPORT.ARTICLE:
            report['strata'][fixture_name].update(php_p99_ms=2, rust_p99_ms=1)
        optional = self.export('per-article-obfuscation', report)
        self.validate(optional)
        for row in optional['rows']:
            if row['phase'] == 'startup-composition':
                self.assertEqual(2, row['warm_php_p99_ms'])
                self.assertEqual(1, row['warm_rust_p99_ms'])
        report.pop('startup')
        self.assertEqual('invalid', self.export('per-article-obfuscation', report)['status'])
        for startup in ({}, dict(runs=True), dict(runs=0), dict(runs=200), dict(runs=200, p99_ms=-1)):
            report['startup'] = startup
            self.assertEqual('invalid', self.export('per-article-obfuscation', report)['status'])

    def test_shared_job_and_public_artifacts_are_independent_on_failure(self):
        workflow = (ROOT / '.github/workflows/archive-sample-performance.yml').read_text().split('  filename-identity:', 1)[1]
        for kind in EXPORT.KINDS:
            self.assertEqual('filename-identity (ubuntu-latest)', PAGES.manual_job_name(kind, 'ubuntu-latest'))
            self.assertIn(f'- name: Export bounded {kind} summary\n        if: always()', workflow)
            self.assertIn(f'- name: Retain public {kind} summary\n        if: always()', workflow)
            self.assertIn(f'public-benchmark-{kind}-${{{{ matrix.runner }}}}', workflow)
        self.assertIn("steps.identity-build.outcome == 'success'", workflow)
        self.assertIn('measurement-before.json', workflow)
        self.assertIn('measurement-after.json', workflow)


if __name__ == '__main__':
    unittest.main()
