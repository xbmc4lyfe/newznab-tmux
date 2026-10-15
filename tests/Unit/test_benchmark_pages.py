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

    def test_actual_sample_plan_source_has_benchmark_mapping_and_public_identity(self):
        root = Path(__file__).parents[2]
        actual = root / 'docker/app/nzb-yenc-ffi/src/sample_plan.rs'
        self.assertTrue(actual.is_file())
        rows = {row['target']: row for row in pages.coverage(root)}
        self.assertEqual(rows[str(actual.relative_to(root))]['coverage'], 'Comparative public caller benchmark')
        self.assertIn('docker/app/nzb-yenc-ffi/src/sample_plan.rs', pages.CALLER_SOURCES)
        spec = importlib.util.spec_from_file_location('caller_export', root / 'scripts/benchmark_callers_summary.py')
        exporter = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(exporter)
        result = exporter.caller_summary('sample-planning', [dict(type='setup', build_source_identities={
            'docker/app/nzb-yenc-ffi/src/sample_plan.rs': 'a' * 64})])
        self.assertEqual(result['source_sha256'], {'docker/app/nzb-yenc-ffi/src/sample_plan.rs': 'a' * 64})

    def test_compact_table_formats_units_ratios_intervals_and_keeps_exact_data(self):
        row = dict(fixture='boundary-1024', phase='cold', samples=31, eligible=True,
                   comparison='exact_public_caller', metric='median_latency',
                   baseline_latency_ms=1.234567891, candidate_latency_ms=0.456789123,
                   baseline_over_candidate=2.702708596, speedup_interval=[1.87654321, 3.1234567])
        rendered = pages.measurement_table([row])
        self.assertIn('1.235 ms', rendered)
        self.assertIn('2.70×', rendered)
        self.assertIn('1.876×', rendered)
        cells = rendered.split('<tr><td>')[1].split('</td>')
        self.assertNotIn('<th>Recorded / diagnostic intervals</th>', rendered)
        self.assertIn('Speedup 95% interval', cells[2])
        self.assertIn('N=31', rendered)
        self.assertIn('Eligible', rendered)
        report = dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[],
                      results=[dict(kind='sample-planning', runner='ubuntu-latest', status='partial', rows=[row])])
        full = pages.render(report)
        self.assertIn('Summary data and identities', full)
        self.assertIn('1.234567891', full)
        self.assertIn('2.702708596', full)

    def test_compact_intervals_round_outward_and_native_diagnostics_have_no_ratio(self):
        row = dict(fixture='<script>unsafe</script>', comparison='native_only_diagnostic',
                   native_median_ms=0.0000004, eligible=False, samples=31, unfamiliar_field='<img src=x onerror=bad>')
        rendered = pages.measurement_table([row])
        self.assertIn('Native-only diagnostic; no baseline', rendered)
        self.assertIn('&lt;0.001 ms', rendered)
        self.assertIn('Ineligible', rendered)
        self.assertIn('unfamiliar_field', rendered)
        self.assertIn('&lt;img', rendered)
        self.assertNotIn('<script>', rendered)
        self.assertNotIn('×', rendered)
        self.assertNotIn('<th>Recorded / diagnostic intervals</th>', rendered)
        self.assertNotIn('<th>Simultaneous decision bounds</th>', rendered)
        interval = pages.display_interval([1.4999, 1.5001], 'ratio')
        self.assertEqual(interval, '[1.499×, 1.501×]')
        control = pages.measurement_table([dict(comparison='same_php_control', baseline_latency_ms=1,
                                               candidate_latency_ms=2, metric='not_applicable')])
        self.assertIn('PHP control; Rust speedup not applicable', control)
        self.assertNotIn('×', control)

    def test_compact_table_preserves_all_legacy_metric_families_and_unknown_values(self):
        rows = [dict(median_rust_over_cpp=.923456, bootstrap_95_percent_interval=[.81234, 1.11234]),
                dict(median_rust_over_php=.712345), dict(median_ms=1.23456, min_ms=.45678, max_ms=9.12345,
                    body_ffi_median_ms={'allocate_and_memcpy': .23456}),
                dict(php_median_ns=1234567, rust_median_ns=456789, improvement_percent=63.012345),
                dict(median_baseline_over_candidate_ratio=2.123456, median_cold_baseline_over_candidate_ratio=.812345),
                dict(baseline_median_ns=8000000, candidate_median_ns=1000000, paired_median_ratio=8.12345,
                    baseline_p99_ns=9000000, candidate_p99_ns=2000000, p99_ratio=4.512345,
                    median_ratio_ci95=[7.12345, 9.12345], p99_ratio_ci95=[3.12345, 5.12345]),
                dict(future_metric={'unknown': '<unsafe>'})]
        rendered = pages.measurement_table(rows)
        for text in ('Rust / C++ latency: 0.92×', 'Rust / PHP latency: 0.71×', 'Minimum: 0.457 ms',
                     'Maximum: 9.123 ms', 'BODY allocate_and_memcpy: 0.235 ms', 'PHP median: 1.235 ms',
                     'Improvement: 63.01%', 'Cold median baseline / candidate: 0.81×',
                     'Baseline p99: 9.000 ms', 'p99 ratio 95% interval', 'future_metric', '&lt;unsafe&gt;'):
            self.assertIn(text, rendered)

    def test_cpu_protocol_two_discloses_historical_resampling_intervals(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[],
                      results=[dict(kind='cpu-snapshot', runner='ubuntu-latest', status='measured', rows=[], measurement_protocol_version=2)])
        self.assertIn('dependent bootstrap samples', pages.render(report))

    def test_corrected_cpu_intervals_are_labeled_separately_from_historical_intervals(self):
        row = dict(kind='cpu-snapshot', runner='ubuntu-latest', status='measured', rows=[],
                   measurement_protocol_version=3, confidence_status='simultaneous_gate_intervals',
                   confidence_family_size=3, head_sha=SHA, runtime_identity={'executed_sha': SHA},
                   protocol_sha256='b' * 64, report_sha256='c' * 64)
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[row])
        text = pages.render(report)
        self.assertNotIn('dependent bootstrap samples', text)
        self.assertIn('simultaneous 95%', text)
        row['status'] = 'invalid'
        self.assertNotIn('CPU protocol 3 uses independent', pages.render(report))
        row['status'] = 'measured'
        row.pop('runtime_identity', None)
        self.assertNotIn('CPU protocol 3 uses independent', pages.render(report))
        row['confidence_status'] = 'unqualified'
        self.assertIn('do not establish qualified uncertainty', pages.render(report))

    def test_unavailable_cpu_provenance_never_invents_historical_protocol(self):
        for version in (None, 0, 3):
            for status in ('missing', 'pending', 'partial', 'invalid', 'measured_gate_failed'):
                with self.subTest(version=version, status=status):
                    row = dict(kind='cpu-snapshot', runner='ubuntu-latest', status=status, rows=[],
                               confidence_status='unqualified')
                    if version is not None:
                        row['measurement_protocol_version'] = version
                    text = pages.render(dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[row]))
                    self.assertNotIn('historical protocol 2', text)
                    self.assertNotIn('dependent bootstrap samples', text)
                    self.assertIn('do not establish qualified uncertainty', text)
                    self.assertIn('CPU protocol 3' if version == 3 else 'protocol provenance is unavailable', text)

    def test_measurement_table_displays_actual_gate_bounds_separately_from_diagnostics(self):
        rows = [dict(fixture='common-mounted', gate_statistic='median_ratio',
                     gate_speedup_simultaneous_ci95=[1.61, 2.41],
                     median_ratio_ci95=[1.81, 2.21], p99_ratio_ci95=[1.71, 2.31]),
                dict(fixture='rare-host-unavailable', gate_statistic='p99_ratio',
                     gate_speedup_simultaneous_ci95=[1.62, 2.42],
                     host_median_speedup_simultaneous_ci95=[2.02, 2.82],
                     median_ratio_ci95=[2.12, 2.72], p99_ratio_ci95=[1.72, 2.32])]
        text = pages.measurement_table(rows)
        self.assertIn('Simultaneous decision bounds', text)
        self.assertIn('Recorded / diagnostic intervals', text)
        for value in ('gate_statistic', 'median_ratio', 'p99_ratio',
                      'gate_speedup_simultaneous_ci95', 'host_median_speedup_simultaneous_ci95',
                      '[1.610×, 2.410×]', '[1.620×, 2.420×]', '[2.020×, 2.820×]',
                      'Median ratio 95% interval', '[1.810×, 2.210×]', '[2.120×, 2.720×]',
                      'p99 ratio 95% interval', '[1.710×, 2.310×]', '[1.720×, 2.320×]'):
            self.assertIn(value, text)
        rows[0]['fixture'] = '<script>fixture</script>'
        rows[0]['gate_statistic'] = '<script>statistic</script>'
        text = pages.measurement_table(rows)
        self.assertNotIn('<script>', text)
        self.assertIn('&lt;script&gt;statistic&lt;/script&gt;', text)

    def test_cpu_confidence_metadata_is_strict_and_does_not_escape_its_comparison(self):
        summary = dict(schema_version=1, repository=pages.REPOSITORY, head_sha=SHA, run_id=12, run_attempt=2, kind='cpp', runner='ubuntu-latest', adoption_qualified=False, rows=[])
        for metadata in ({'confidence_status': 'simultaneous_gate_intervals'},
                         {'measurement_protocol_version': 3}, {'confidence_family_size': 3}):
            with self.subTest(metadata=metadata), self.assertRaises(ValueError):
                pages.validate_summary({**summary, **metadata}, self.run_row(), 'cpp', 'ubuntu-latest')

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

    def test_withdrawn_fingerprint_status_is_validated_and_visible(self):
        summary = dict(schema_version=1, kind='fingerprint-ffi', runner='ubuntu-latest', head_sha=SHA,
                       run_id=12, run_attempt=2, repository=pages.REPOSITORY, rows=[], adoption_qualified=False,
                       candidate_status='experimental_withdrawn_from_production')
        pages.validate_summary(summary, self.run_row(), 'fingerprint-ffi', 'ubuntu-latest')
        rendered = pages.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[summary]))
        self.assertIn('withdrawn from the application runtime', rendered)
        summary['candidate_status'] = 'production'
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, self.run_row(), 'fingerprint-ffi', 'ubuntu-latest')

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
                         {(kind, runner) for kind in ('sample-planning', 'archive-parsing', 'vendor-archive', 'metadata-modules', 'archive-modules')
                          for runner in ('ubuntu-latest', 'ubuntu-24.04-arm')})
        for kind in ('sample-planning', 'archive-parsing', 'vendor-archive'):
            summary = self.caller_summary(kind)
            pages.validate_summary(summary, self.run_row(), kind, 'ubuntu-latest')
            rendered = pages.render(dict(head_sha=SHA, generated_at='fixture', workflows=[], coverage=[], results=[summary]))
            self.assertIn('p99_unavailable', rendered)
            self.assertIn('Tail unavailable', rendered)
            self.assertNotIn('unpublished-synthetic-payload', rendered)

    def test_archive_source_coverage_keeps_header_projection_separate_from_ranges_and_iso(self):
        root = Path(__file__).parents[2]
        rows = {Path(row['target']).stem: row for row in pages.coverage(root)}
        self.assertEqual(rows['formats']['coverage'], 'Shared header caller comparison and diagnostics')
        self.assertIn('ISO have no equivalent baseline', rows['formats']['detail'])
        for name in ('seven_zip_ranges', 'par2_source_ranges'):
            self.assertEqual(rows[name]['coverage'], 'Native caller diagnostics')
            self.assertIn('no equivalent PHP range baseline', rows[name]['detail'])

    def test_vendor_archive_pages_rejects_unlocked_sources_wrong_family_and_full_semantic_claim(self):
        valid = self.caller_summary('vendor-archive')
        pages.validate_summary(valid, self.run_row(), 'vendor-archive', 'ubuntu-latest')
        rendered = pages.measurement_table(valid['rows'])
        self.assertIn('Shared archive header projection only', rendered)
        self.assertIn('Tail unavailable', rendered)
        mutations = (
            lambda s: s['source_sha256'].pop('vendor/dariusiii/rarinfo/RarInfo.php'),
            lambda s: s['source_sha256'].update({'vendor/dariusiii/rarinfo/tests/fixtures/rar/store_method.rar': 'a' * 64}),
            lambda s: s['source_sha256'].update({'vendor/unreviewed.rar': 'a' * 64}),
            lambda s: s['rows'][0].update(confidence_family_size=16),
            lambda s: s['rows'][0].update(comparison='exact_public_caller'),
            lambda s: s['rows'][0].update(speedup_interval=[1.8, 4]),
            lambda s: s['rows'][0].update(baseline_latency_interval_ms=[6, 8]),
            lambda s: s.update(adoption_qualified=True),
            lambda s: s.update(evidence_type='full_archive_semantics'),
        )
        for mutation in mutations:
            summary = copy.deepcopy(valid)
            mutation(summary)
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                pages.validate_summary(summary, self.run_row(), 'vendor-archive', 'ubuntu-latest')

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
                 uncertainty_method='mixed_output_bootstrap', measurement_protocol_version=2)])
        rendered = pages.render(report)
        self.assertIn('corrected mixed-output resampling', rendered)
        self.assertIn('pointwise intervals only', rendered)
        self.assertIn('do not establish qualified uncertainty', rendered)

    def test_live_cpu_family_is_separate_and_its_bounds_stay_on_host_fixture(self):
        summary = dict(schema_version=1, repository=pages.REPOSITORY, head_sha=SHA,
            run_id=12, run_attempt=2, kind='cpu-snapshot', runner='ubuntu-latest',
            adoption_qualified=False, measurement_protocol_version=3, confidence_status='unqualified',
            confidence_family_size=3, host_confidence_status='simultaneous_gate_intervals',
            host_confidence_family_size=2, baseline_revision='5cc5303b502e653d83bba1951dec2f5d2da1db35',
            rows=[dict(fixture='host-live', comparison='exact_host_cli', samples=1000,
                       p99_sample_sufficient=True, live_median_speedup_simultaneous_ci95=[2, 3],
                       live_p99_speedup_simultaneous_ci95=[1.6, 2])])
        pages.validate_summary(summary, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')
        for change in (dict(host_confidence_family_size=3), dict(measurement_protocol_version=2),
                       dict(host_confidence_status='invented')):
            with self.subTest(change=change), self.assertRaises(ValueError):
                pages.validate_summary({**summary, **change}, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')
        moved = copy.deepcopy(summary)
        moved['rows'][0]['fixture'] = 'common-mounted'
        with self.assertRaises(ValueError):
            pages.validate_summary(moved, self.run_row(), 'cpu-snapshot', 'ubuntu-latest')
        rendered = pages.measurement_table(summary['rows'])
        self.assertIn('live_median_speedup_simultaneous_ci95: [2.000×, 3.000×]', rendered)
        self.assertIn('live_p99_speedup_simultaneous_ci95: [1.600×, 2.000×]', rendered)

    def test_frozen_reports_link_remains_separate_from_current_results(self):
        report = dict(head_sha=SHA, generated_at='now', workflows=[], coverage=[], results=[], frozen_reports_available=True)
        self.assertIn('href="benchmarks/"', pages.render(report))
        self.assertIn('separate from this build', pages.render(report))


if __name__ == '__main__':
    unittest.main()
