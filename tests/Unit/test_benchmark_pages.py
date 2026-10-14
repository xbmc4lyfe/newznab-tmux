import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

SPEC = importlib.util.spec_from_file_location('pages', Path(__file__).parents[2] / 'scripts/benchmark-pages.py')
pages = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pages)
SHA = 'a' * 40


class PagesTest(unittest.TestCase):
    def run_row(self, **changes):
        return dict(id=12, run_attempt=2, head_sha=SHA, head_branch='master', event='push',
                    status='completed', conclusion='failure', head_repository={'full_name': pages.REPOSITORY}, **changes)

    def test_failed_latest_run_supersedes_success_and_dispatch_is_excluded(self):
        old = self.run_row()
        old.update(id=11, conclusion='success')
        manual = self.run_row()
        manual.update(id=13, event='workflow_dispatch')
        self.assertEqual(12, pages.select_run([old, self.run_row(), manual], SHA)['id'])
        self.assertIsNone(pages.select_run([manual], SHA))
        self.assertIsNone(pages.select_run([dict(old, head_repository=None)], SHA))
        self.assertIsNone(pages.select_run([dict(old, head_sha=None)], None))

    def test_archive_is_bounded_and_exact_member_only(self):
        for members in [('summary.json', 'extra.txt'), ('../summary.json',), ('summary.json', 'summary.json')]:
            data = io.BytesIO()
            with zipfile.ZipFile(data, 'w') as archive:
                for name in members:
                    archive.writestr(name, '{}')
            with self.assertRaises(ValueError):
                pages.read_summary(data.getvalue())
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as archive:
            archive.writestr('summary.json', '{"rows": [NaN]}')
        with self.assertRaises(ValueError):
            pages.read_summary(data.getvalue())

    def test_identity_requires_current_attempt_and_runner(self):
        row = dict(schema_version=1, kind='php', runner='ubuntu-latest', head_sha=SHA,
                   run_id=12, run_attempt=1, repository=pages.REPOSITORY, rows=[], adoption_qualified=False)
        with self.assertRaises(ValueError):
            pages.validate_summary(row, self.run_row(), 'php', 'ubuntu-latest')
        row['run_attempt'] = 2
        pages.validate_summary(row, self.run_row(), 'php', 'ubuntu-latest')

    def test_missing_results_and_new_modules_are_visible_and_html_is_escaped(self):
        with tempfile.TemporaryDirectory() as root:
            source = Path(root) / 'docker/app/nzb-yenc-ffi/src'
            source.mkdir(parents=True)
            (source / 'new_target.rs').write_text('')
            coverage = pages.coverage(Path(root))
            self.assertEqual('No automated benchmark', coverage[0]['coverage'])
            report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=coverage,
                          results=[dict(kind='php', runner='ubuntu-latest', status='<script>oops</script>', rows=[])])
            rendered = pages.render(report)
            self.assertIn('&lt;script&gt;', rendered)
            self.assertNotIn('<script>oops', rendered)
            self.assertIn('p99', rendered)
            self.assertIn('new_target.rs', rendered)

    def test_api_failure_is_unavailable_not_not_run(self):
        def failure(_):
            raise OSError('network failure')
        report = pages.collect(SHA, Path('.'), failure)
        self.assertTrue(all(w['status'] == 'Collection unavailable' for w in report['workflows']))
        self.assertTrue(all(r['status'] == 'Collection unavailable' for r in report['results']))

    def test_current_attempt_artifact_and_missing_matrix_are_distinct(self):
        def fetch(path):
            if '/artifacts?' in path:
                return {'artifacts': [dict(name='public-benchmark-php-ubuntu-latest-12-2', id=9, expired=False, size_in_bytes=100),
                                      dict(name='public-benchmark-cpp-ubuntu-latest-12-1', id=8, expired=False, size_in_bytes=100)]}
            return {'workflow_runs': [self.run_row()]}
        summary = dict(schema_version=1, kind='php', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, rows=[], status='partial', adoption_qualified=False)
        archive = io.BytesIO()
        with zipfile.ZipFile(archive, 'w') as output:
            output.writestr('summary.json', json.dumps(summary))
        report = pages.collect(SHA, Path('.'), fetch, lambda _: archive.getvalue())
        states = {(r['kind'], r['runner']): r['status'] for r in report['results']}
        self.assertEqual('partial', states['php', 'ubuntu-latest'])
        self.assertEqual('Summary unavailable', states['php', 'ubuntu-24.04-arm'])
        self.assertEqual('Summary unavailable', states['cpp', 'ubuntu-latest'])
        self.assertEqual('failure', report['results'][2]['workflow_status'])

    def test_private_payload_field_is_rejected(self):
        summary = dict(schema_version=1, kind='php', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, rows=[{'command': 'secret'}], adoption_qualified=False)
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'php', 'ubuntu-latest')

    def test_overflow_float_is_rejected(self):
        with self.assertRaises(ValueError):
            pages.strict_json('{"value":1e999}')

    def complete_cpp(self, run, runner):
        import itertools
        return dict(schema_version=1, kind='cpp', runner=runner, head_sha=run['head_sha'], run_id=run['id'],
                    run_attempt=run['run_attempt'], repository=pages.REPOSITORY, adoption_qualified=False,
                    evidence_type='paired_median_screen', status='inconclusive', completeness={'complete': True, 'expected_rows': 24, 'observed_rows': 24},
                    rows=[dict(size=size, density=density, path=path, eligible_pairs=31, invalid_pairs=0,
                               decision='inconclusive', median_rust_over_cpp=0.9, bootstrap_95_percent_interval=[0.8, 1.1])
                          for size, density, path in itertools.product((4096, 524288, 1048576, 8388608), ('ordinary', 'heavy'), ('tolerant', 'strict', 'body'))])

    def test_last_valid_complete_cpp_is_separate_and_survives_new_profile_only_run(self):
        newer = self.run_row()
        older = self.run_row()
        older.update(id=11, head_sha='b' * 40)
        archives = {}
        for artifact_id, runner in ((1, 'ubuntu-latest'), (2, 'ubuntu-24.04-arm')):
            buffer = io.BytesIO()
            with zipfile.ZipFile(buffer, 'w') as archive:
                archive.writestr('summary.json', json.dumps(self.complete_cpp(older, runner)))
            archives[artifact_id] = buffer.getvalue()
        def fetch(path):
            if path.startswith('actions/workflows/'):
                return {'workflow_runs': [newer, older]}
            if path.startswith('actions/runs/12/'):
                return {'artifacts': []}
            return {'artifacts': [dict(name=f'public-benchmark-cpp-{runner}-11-2', id=i, expired=False, size_in_bytes=100)
                                  for i, runner in ((1, 'ubuntu-latest'), (2, 'ubuntu-24.04-arm'))]}
        history = pages.last_valid_comparison(fetch, lambda i: archives[i])
        self.assertEqual(history['status'], 'Available historical comparison')
        self.assertEqual(history['head_sha'], older['head_sha'])
        self.assertEqual(history['run_id'], 11)
        self.assertEqual(len(history['results']), 2)
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[], last_valid_comparison=history)
        rendered = pages.render(report)
        self.assertIn('Last valid complete C++ comparison', rendered)
        self.assertIn(older['head_sha'], rendered)

    def test_last_valid_does_not_reuse_prior_attempt_partial_or_untrusted_runs(self):
        run = self.run_row()
        for changes in ({'event': 'pull_request'}, {'head_repository': {'full_name': 'other/repo'}}, {}):
            candidate = dict(run, **changes)
            def fetch(path):
                if path.startswith('actions/workflows/'):
                    return {'workflow_runs': [candidate]}
                return {'artifacts': [dict(name='public-benchmark-cpp-ubuntu-latest-12-1', id=1, expired=False, size_in_bytes=100)]}
            result = pages.last_valid_comparison(fetch, lambda _: self.fail('Wrong attempt or untrusted artifact must not be downloaded'))
            self.assertNotEqual(result['status'], 'Available historical comparison')

    def test_utility_summary_runtime_identity_and_public_fields(self):
        summary = dict(schema_version=1, kind='cpu-snapshot', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, rows=[], adoption_qualified=False,
                       runtime_identity={'executed_sha': SHA, 'command': 'private'})
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')
        summary['runtime_identity'] = {'executed_sha': 'b' * 40}
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')
        summary['runtime_identity'] = {'executed_sha': SHA}
        pages.validate_summary(summary, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')

    def test_utility_family_coverage_and_new_auxiliary_remains_explicit_gap(self):
        with tempfile.TemporaryDirectory() as root:
            source = Path(root) / 'docker/app/nzb-yenc-ffi/src'
            source.mkdir(parents=True)
            for name in ('article_fingerprint', 'cpu_snapshot', 'new_auxiliary'):
                (source / (name + '.rs')).write_text('')
            rows = {Path(row['target']).stem: row for row in pages.coverage(Path(root))}
            self.assertIn('Comparative', rows['article_fingerprint']['coverage'])
            self.assertIn('Comparative', rows['cpu_snapshot']['coverage'])
            self.assertEqual(rows['new_auxiliary']['coverage'], 'No automated benchmark')

    def test_frozen_reports_link_remains_separate_from_current_results(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[], frozen_reports_available=True)
        self.assertIn('href="benchmarks/"', pages.render(report))
        self.assertIn('separate from this build', pages.render(report))


if __name__ == '__main__':
    unittest.main()
