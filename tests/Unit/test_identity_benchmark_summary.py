"""Identity report publication preserves provenance, routing and uncertainty scope."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

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
        report.update(cold={}, startup_bounded_p99={}, startup=dict(raw=[MARKER]))
        for name, category in EXPORT.ARTICLE.items():
            report['strata'][name] = dict(category=category, statistic='p99' if category == 'uncommon' else 'median',
                samples=1000, speedup=2, speedup_ci95=[1.7, 2.3], php_ms=2, rust_ms=1, declined=False,
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
            self.assertTrue(all(row['eligible'] for row in result['rows']))
            self.assertFalse(result['adoption_qualified'])
            self.assertEqual(EXPORT.SOURCE_PATHS | EXPORT.RESOURCE_KEYS, set(result['source_sha256']))

    def test_source_library_runtime_and_resource_drift_fail_closed(self):
        mutations = [
            ('measurement-after.json', lambda value: value.update(library_sha256='d' * 64)),
            ('measurement-after.json', lambda value: value['source_sha256'].update({'composer.lock': 'd' * 64})),
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
