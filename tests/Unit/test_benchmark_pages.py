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

    def test_frozen_reports_link_remains_separate_from_current_results(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[], frozen_reports_available=True)
        self.assertIn('href="benchmarks/"', pages.render(report))
        self.assertIn('separate from this build', pages.render(report))


if __name__ == '__main__':
    unittest.main()
