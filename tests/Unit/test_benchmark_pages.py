import copy
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
    def test_payload_mode_is_a_validated_enum_and_legacy_results_are_explicitly_unknown(self):
        summary = dict(schema_version=1, kind='cpp', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, rows=[], adoption_qualified=False)
        for mode in ('incumbent', 'experimental', 'unknown'):
            summary['rust_payload_mode'] = mode
            pages.validate_summary(summary, self.run_row(), 'cpp', 'ubuntu-latest')
        for invalid in ('<script>bad</script>', [], None, True, 'not_applicable'):
            summary['rust_payload_mode'] = invalid
            with self.assertRaises(ValueError):
                pages.validate_summary(summary, self.run_row(), 'cpp', 'ubuntu-latest')
        del summary['rust_payload_mode']
        pages.validate_summary(summary, self.run_row(), 'cpp', 'ubuntu-latest')
        rendered = pages.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[summary]))
        self.assertIn('Rust payload mode: unknown', rendered)


    def test_default_and_experimental_source_coverage_have_separate_comparisons(self):
        with tempfile.TemporaryDirectory() as root:
            source = Path(root) / 'docker/app/nzb-yenc-ffi/src'
            source.mkdir(parents=True)
            names = ('payload_decoder', 'payload_decoder_arm', 'payload_decoder_x86')
            for name in names:
                (source / (name + '.rs')).touch()
                (source / (name + '_experimental.rs')).touch()
            rows = {Path(row['target']).stem: row for row in pages.coverage(Path(root))}
            for name in names:
                self.assertEqual(['incumbent'], rows[name]['payload_modes'])
                self.assertEqual(['php'], rows[name]['comparisons'])
                self.assertEqual(['experimental'], rows[name + '_experimental']['payload_modes'])
                self.assertEqual(['cpp'], rows[name + '_experimental']['comparisons'])
                self.assertNotEqual('No automated benchmark', rows[name + '_experimental']['coverage'])



    def test_unsplit_source_coverage_does_not_claim_experimental_execution(self):
        with tempfile.TemporaryDirectory() as root:
            source = Path(root) / 'docker/app/nzb-yenc-ffi/src'
            source.mkdir(parents=True)
            (source / 'payload_decoder.rs').touch()
            row = pages.coverage(Path(root))[0]
            self.assertEqual(['incumbent'], row['payload_modes'])
            self.assertEqual(['php', 'cpp'], row['comparisons'])
            self.assertIn('separate from measured mode', row['detail'])

    def test_failed_or_unsafe_mode_evidence_is_never_rendered_as_measured(self):
        for status in ('invalid', 'partial', 'missing', 'failure', 'cancelled'):
            self.assertEqual('unknown', pages.measured_payload_mode({'status': status, 'rust_payload_mode': 'incumbent'}))
        self.assertEqual('unknown', pages.measured_payload_mode({'status': 'ok', 'rust_payload_mode': ['incumbent']}))
        self.assertEqual('experimental', pages.measured_payload_mode({'status': 'inconclusive', 'rust_payload_mode': 'experimental'}))

    def test_cpu_results_disclose_unqualified_resampling_intervals(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[],
                      results=[dict(kind='cpu-snapshot', runner='ubuntu-latest', status='measured', rows=[])])
        self.assertIn('dependent bootstrap samples', pages.render(report))

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

    def caller_summary(self, kind='sample-planning'):
        root = Path(__file__).parents[2]
        spec = importlib.util.spec_from_file_location('caller_fixture', root / 'tests/Unit/test_benchmark_callers_export.py')
        fixture = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixture)
        spec = importlib.util.spec_from_file_location('caller_export', root / 'scripts/benchmark_callers_summary.py')
        exporter = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(exporter)
        summary = exporter.caller_summary(kind, fixture.fixture(kind))
        summary['source_sha256'].update(dict.fromkeys(pages.RESOURCE_HASHES, 'f' * 64))
        summary.update(schema_version=1, kind=kind, runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, adoption_qualified=False,
                       report_sha256='e' * 64, runtime_identity={'executed_sha': SHA})
        return summary

    def test_new_caller_workflow_matrix_and_safe_nullable_tail_are_published(self):
        self.assertEqual(set(pages.WORKFLOWS['archive-sample-performance.yml']),
                         {(kind, runner) for kind in ('sample-planning', 'archive-parsing')
                          for runner in ('ubuntu-latest', 'ubuntu-24.04-arm')})
        for kind in ('sample-planning', 'archive-parsing'):
            summary = self.caller_summary(kind)
            pages.validate_summary(summary, self.run_row(), kind, 'ubuntu-latest')
            rendered = pages.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[summary]))
            self.assertIn('p99_unavailable', rendered)
            self.assertIn('null, null', rendered)
            self.assertNotIn('unpublished-synthetic-payload', rendered)

    def test_caller_diagnostics_controls_and_unmeasured_tails_cannot_claim_speedups(self):
        for kind, category, patch in (
                ('archive-parsing', 'diagnostic', {'baseline_latency_ms': 10}),
                ('archive-parsing', 'diagnostic', {'baseline_over_candidate': 3}),
                ('sample-planning', 'dispatch_control', {'baseline_over_candidate': 2}),
                ('sample-planning', 'uncommon', {'qualification_gate_passed': True}),
                ('sample-planning', 'uncommon', {'metric': 'p99_latency'}),
                ('sample-planning', 'uncommon', {'speedup_interval': [1.5, 3]}),
                ('sample-planning', 'uncommon', {'baseline_over_candidate': 2})):
            summary = self.caller_summary(kind)
            next(row for row in summary['rows'] if row['category'] == category).update(patch)
            with self.subTest(kind=kind, category=category, patch=patch), self.assertRaises(ValueError):
                pages.validate_summary(summary, self.run_row(), kind, 'ubuntu-latest')

    def test_caller_provenance_schema_identity_and_fixture_checks_fail_closed(self):
        valid = self.caller_summary()
        mutations = (
            lambda s: s.update(report_sha256='private path'),
            lambda s: s.update(protocol_sha256=None),
            lambda s: s.update(runtime_identity={'executed_sha': 'b' * 40}),
            lambda s: s.pop('runtime_identity'),
            lambda s: s.update(source_sha256={'../private': 'a' * 64}),
            lambda s: s['source_sha256'].update({'private_name': 'a' * 64}),
            lambda s: s['source_sha256'].pop('resource_limits_comparison'),
            lambda s: s.update(schema_version=True),
            lambda s: s.update(head_sha='b' * 40),
            lambda s: s.update(run_attempt=1),
            lambda s: s['rows'].append(copy.deepcopy(s['rows'][0])),
            lambda s: s['rows'][0].update(category='common'),
            lambda s: s['rows'][0].update(private='secret'),
            lambda s: s['rows'][0].update(samples=True),
            lambda s: s['rows'].pop(),
        )
        for mutation in mutations:
            summary = copy.deepcopy(valid)
            mutation(summary)
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                pages.validate_summary(summary, self.run_row(), 'sample-planning', 'ubuntu-latest')

    def test_extended_fingerprint_sub1000_p99_and_cpu_gate_cannot_qualify(self):
        summary = dict(schema_version=1, kind='fingerprint-ffi', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, adoption_qualified=False,
                       baseline_revision='a1a9b0829610c3b62e7d0bdb418669a89c36c90b', rows=[dict(
                           fixture='ids16', phase='cold', samples=31, p99_sample_sufficient=False,
                           p99_ratio=None, p99_ratio_ci95=None, p99_threshold_met=None)])
        pages.validate_summary(summary, self.run_row(), 'fingerprint-ffi', 'ubuntu-latest')
        summary['rows'][0]['p99_ratio'] = 2
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'fingerprint-ffi', 'ubuntu-latest')
        summary.update(kind='cpu-snapshot', baseline_revision='5cc5303b502e653d83bba1951dec2f5d2da1db35',
                       rows=[dict(fixture='host-live', comparison='exact_host_cli', samples=31,
                                  p99_sample_sufficient=False, gate_passed=True)])
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')

    def test_corrected_cpu_intervals_keep_separate_historical_caveat(self):
        report = dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[
            dict(kind='cpu-snapshot', runner='ubuntu-latest', status='measured', rows=[],
                 uncertainty_method='mixed_output_bootstrap')])
        rendered = pages.render(report)
        self.assertIn('corrected mixed-output resampling', rendered)
        self.assertIn('Historical protocol limits', rendered)
        self.assertIn('neither establishes production throughput or adoption', rendered)

    def test_frozen_reports_link_remains_separate_from_current_results(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[], frozen_reports_available=True)
        self.assertIn('href="benchmarks/"', pages.render(report))
        self.assertIn('separate from this build', pages.render(report))


if __name__ == '__main__':
    unittest.main()
