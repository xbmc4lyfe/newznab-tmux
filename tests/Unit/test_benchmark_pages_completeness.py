import copy
import importlib.util
import io
import itertools
import json
from pathlib import Path
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('complete_pages', ROOT / 'scripts/benchmark-pages.py')
pages = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(pages)
SHA = 'a' * 40


def run(event='push', run_id=12, attempt=2):
    return dict(id=run_id, run_attempt=attempt, head_sha=SHA, head_branch='master', event=event,
                status='completed', conclusion='success', head_repository={'full_name': pages.REPOSITORY})


def decoder():
    return dict(schema_version=1, repository=pages.REPOSITORY, head_sha=SHA, run_id=12, run_attempt=2,
                kind='cpp', runner='ubuntu-latest', adoption_qualified=False, status='inconclusive',
                provenance_status='verified_current', runtime_identity={'executed_sha': SHA},
                source_sha256={str(p.relative_to(ROOT)): 'b' * 64 for p in (ROOT / 'docker/app/nzb-yenc-ffi/src').rglob('*.rs')},
                library_sha256={'cpp': 'b' * 64, 'rust': 'c' * 64}, runtime_manifest_sha256='d' * 64,
                report_sha256='e' * 64, protocol_sha256='f' * 64, kernel_qualification_sha256='b' * 64,
                rust_payload_kernel={'id': 3, 'name': 'avx512-vbmi2'}, required_payload_kernel={'id': 3, 'name': 'avx512-vbmi2'},
                hardware_qualification='designated_kernel',
                completeness=dict(complete=True, expected_rows=24, observed_rows=24),
                rows=[dict(size=size, density=density, path=path, eligible_pairs=31, invalid_pairs=0,
                           decision='inconclusive', median_rust_over_cpp=.9, bootstrap_95_percent_interval=[.8, 1.1])
                      for size, density, path in itertools.product((4096, 524288, 1048576, 8388608),
                                                                 ('ordinary', 'heavy'), ('tolerant', 'strict', 'body'))])


class CompletenessTest(unittest.TestCase):
    def test_complete_decoder_with_frozen_current_source_provenance_is_accepted(self):
        pages.validate_summary(decoder(), run(), 'cpp', 'ubuntu-latest')

    def test_complete_decoder_cannot_drop_an_implementation_or_claim_an_unobserved_kernel(self):
        for change in ('source', 'kernel'):
            summary = decoder()
            if change == 'source':
                summary['source_sha256'].pop('docker/app/nzb-yenc-ffi/src/payload_decoder_x86.rs')
            else:
                summary['rust_payload_kernel'] = {'id': 2, 'name': 'avx2'}
            with self.subTest(change=change), self.assertRaises(ValueError):
                pages.validate_summary(summary, run(), 'cpp', 'ubuntu-latest')

    def test_empty_complete_improvement_is_rejected(self):
        summary = decoder()
        summary.update(status='useful_improvement', rows=[])
        with self.assertRaises(ValueError):
            pages.validate_summary(summary, run(), 'cpp', 'ubuntu-latest')

    def test_duplicate_missing_and_bad_counts_cannot_claim_complete_measurements(self):
        for change in ('duplicate', 'missing', 'boolean-count', 'negative-ratio', 'reversed-interval', 'wrong-status'):
            summary = decoder()
            if change == 'duplicate':
                summary['rows'][-1] = copy.deepcopy(summary['rows'][0])
            elif change == 'missing':
                summary['rows'].pop()
            elif change == 'boolean-count':
                summary['rows'][0]['eligible_pairs'] = True
            elif change == 'negative-ratio':
                summary['rows'][0]['median_rust_over_cpp'] = -1
            elif change == 'reversed-interval':
                summary['rows'][0]['bootstrap_95_percent_interval'] = [2, 1]
            else:
                summary['status'] = 'useful_improvement'
            with self.subTest(change=change), self.assertRaises(ValueError):
                pages.validate_summary(summary, run(), 'cpp', 'ubuntu-latest')

    def test_actual_xz_source_gets_an_explicit_comparison_gap(self):
        rows = {Path(r['target']).stem: r for r in pages.coverage(ROOT)}
        for name in ('iso_path_tables', 'zstd_frame', 'xz_first_block'):
            with self.subTest(name=name):
                self.assertEqual('No automated benchmark', rows[name]['coverage'])
                self.assertIn('Explicit coverage gap', rows[name]['detail'])
        for name in ('abi_crc', 'abi_decoder', 'abi_decoder_arm', 'abi_decoder_x86'):
            with self.subTest(name=name):
                self.assertEqual('No automated benchmark', rows[name]['coverage'])
                self.assertEqual(['experimental'], rows[name]['payload_modes'])
                self.assertIn('Explicit coverage gap', rows[name]['detail'])
        for name in ('abi_receipts', 'benchmark', 'evidence', 'installation', 'tooling',
                     'install-yenc', 'qualify-yenc', 'verify-yenc-evidence'):
            with self.subTest(name=name):
                self.assertEqual('Auxiliary correctness only', rows[name]['coverage'])
                self.assertIn('does not measure its own performance', rows[name]['detail'])
        for row in rows.values():
            self.assertNotEqual('Correctness tests or utility execution do not measure speed.', row['detail'])

    def test_every_public_export_is_in_the_collected_report(self):
        def unavailable(_):
            raise OSError('offline')
        report = pages.collect(SHA, ROOT, unavailable)
        self.assertEqual(44, len(report.get('rust_exports', [])))
        self.assertIn('Public Rust interfaces', pages.render(report))
        self.assertIn('nntmux_nntp_header_pair_comparison_json', pages.render(report))

    def test_trusted_selective_master_dispatch_is_separate_from_push_screen(self):
        manual = run('workflow_dispatch', 13)
        summary = json.loads((ROOT / 'github-pages/docs/data/study-six-metadata-modules-x86.json').read_text())
        summary.update(head_sha=SHA, run_id=13, run_attempt=2)
        summary['runtime_identity']['executed_sha'] = SHA
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('summary.json', json.dumps(summary))
        def fetch(path):
            if 'event=workflow_dispatch' in path:
                return {'workflow_runs': [manual] if 'archive-sample-performance' in path else []}
            if '/attempts/2/jobs' in path:
                return {'jobs': [{'name': 'metadata-modules (ubuntu-latest)'}]}
            if '/13/artifacts?' in path:
                return {'artifacts': [dict(name='public-benchmark-metadata-modules-ubuntu-latest-13-2',
                                          id=9, expired=False, size_in_bytes=100)]}
            if '/artifacts?' in path:
                return {'artifacts': []}
            return {'workflow_runs': [run()]}
        report = pages.collect(SHA, ROOT, fetch, lambda _: buffer.getvalue())
        self.assertEqual(27, len(report['results']))
        dispatched = report.get('dispatch_results', [])
        self.assertEqual(1, len(dispatched))
        self.assertEqual('qualification', dispatched[0]['benchmark_mode'])
        self.assertEqual(1000, dispatched[0]['rows'][0]['samples'])
        self.assertEqual(13, dispatched[0]['run_id'])
        self.assertIn('Trusted manual measurements', pages.render(report))


    def test_manual_dispatch_rejects_foreign_repository_branch_sha_and_event(self):
        valid = run('workflow_dispatch', 20)
        rejected = []
        for index, fields in enumerate((
            {'head_repository': {'full_name': 'NNTmux/newznab-tmux'}},
            {'head_branch': 'feature/untrusted'},
            {'head_sha': 'b' * 40},
            {'event': 'pull_request'},
            {'head_repository': None},
            {'run_attempt': True},
        ), start=21):
            rejected.append(dict(run('workflow_dispatch', index), **fields))
        inspected = []

        def fetch(path):
            if '/workflows/' in path:
                return {'workflow_runs': [valid] + rejected if 'yenc-performance.yml' in path else []}
            inspected.append(path)
            if '/20/artifacts?' in path:
                return {'artifacts': []}
            if '/20/attempts/2/jobs?' in path:
                return {'jobs': [{'name': 'paired-decoding (ubuntu-latest)'}]}
            self.fail(f'Untrusted dispatch was inspected: {path}')

        results, runs = pages.collect_dispatches(SHA, fetch, lambda _: self.fail('Unexpected download'))
        self.assertEqual([20], [row['run_id'] for row in runs])
        self.assertEqual([20], [row['run_id'] for row in results])
        self.assertEqual(2, len(inspected))
        self.assertEqual('xbmc4lyfe/newznab-tmux', pages.REPOSITORY)

    def test_manual_dispatch_limits_newest_three_independently_per_producer(self):
        inspected = []

        def fetch(path):
            if '/workflows/' in path:
                ids = ([12, 14, 11, 13] if 'yenc-performance.yml' in path else
                       [22, 24, 21, 23] if 'archive-sample-performance.yml' in path else [])
                return {'workflow_runs': [run('workflow_dispatch', number) for number in ids]}
            inspected.append(path)
            return {'artifacts': []} if '/artifacts?' in path else {'jobs': []}

        results, runs = pages.collect_dispatches(SHA, fetch, lambda _: self.fail('Unexpected download'))
        self.assertEqual([], results)
        self.assertEqual([14, 13, 12, 24, 23, 22], [row['run_id'] for row in runs])
        self.assertEqual(12, len(inspected))
        self.assertFalse(any('/11/' in path or '/21/' in path for path in inspected))

    def test_latest_manual_attempt_cannot_borrow_previous_attempt_artifact(self):
        def fetch(path):
            if '/workflows/' in path:
                return {'workflow_runs': [run('workflow_dispatch', 30, 3)] if 'yenc-performance.yml' in path else []}
            if '/30/artifacts?' in path:
                return {'artifacts': [dict(name='public-benchmark-cpp-ubuntu-latest-30-2',
                                           id=90, expired=False, size_in_bytes=100)]}
            self.assertIn('/30/attempts/3/jobs?', path)
            return {'jobs': [{'name': 'paired-decoding (ubuntu-latest)'}]}

        results, runs = pages.collect_dispatches(SHA, fetch, lambda _: self.fail('Prior attempt downloaded'))
        self.assertEqual(3, runs[0]['run_attempt'])
        self.assertEqual(1, len(results))
        self.assertEqual(3, results[0]['run_attempt'])
        self.assertEqual('Summary unavailable', results[0]['status'])
        self.assertEqual([], results[0]['rows'])
        self.assertNotIn('artifact_id', results[0])

    def test_latest_named_artifact_cannot_hide_previous_attempt_provenance(self):
        summary = decoder()
        summary.update(run_id=30, run_attempt=2)
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('summary.json', json.dumps(summary))

        def fetch(path):
            if '/workflows/' in path:
                return {'workflow_runs': [run('workflow_dispatch', 30, 3)] if 'yenc-performance.yml' in path else []}
            if '/30/artifacts?' in path:
                return {'artifacts': [dict(name='public-benchmark-cpp-ubuntu-latest-30-3',
                                           id=90, expired=False, size_in_bytes=100)]}
            return {'jobs': [{'name': 'paired-decoding (ubuntu-latest)'}]}

        results, _ = pages.collect_dispatches(SHA, fetch, lambda _: buffer.getvalue())
        self.assertEqual('Summary invalid or unavailable', results[0]['status'])
        self.assertEqual([], results[0]['rows'])
        self.assertNotIn('artifact_id', results[0])

    def test_failed_manual_selection_stays_visible_ahead_of_older_success(self):
        failed = dict(run('workflow_dispatch', 32), conclusion='failure')
        summary = decoder()
        summary.update(run_id=31)
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('summary.json', json.dumps(summary))

        def fetch(path):
            if '/workflows/' in path:
                return {'workflow_runs': [run('workflow_dispatch', 31), failed] if 'yenc-performance.yml' in path else []}
            if '/31/artifacts?' in path:
                return {'artifacts': [dict(name='public-benchmark-cpp-ubuntu-latest-31-2',
                                           id=91, expired=False, size_in_bytes=100)]}
            if '/artifacts?' in path:
                return {'artifacts': []}
            return {'jobs': [{'name': 'paired-decoding (ubuntu-latest)'}]}

        results, runs = pages.collect_dispatches(SHA, fetch, lambda _: buffer.getvalue())
        self.assertEqual([32, 31], [row['run_id'] for row in runs])
        self.assertEqual('failure', runs[0]['status'])
        self.assertEqual([32, 31], [row['run_id'] for row in results])
        self.assertEqual('failure', results[0]['workflow_status'])
        self.assertEqual('Summary unavailable', results[0]['status'])
        self.assertEqual('inconclusive', results[1]['status'])
        self.assertEqual(24, len(results[1]['rows']))

    def test_unavailable_manual_job_selection_remains_visible(self):
        def fetch(path):
            if '/workflows/' in path:
                return {'workflow_runs': [run('workflow_dispatch', 33)] if 'yenc-performance.yml' in path else []}
            if '/artifacts?' in path:
                return {'artifacts': []}
            raise OSError('Jobs API unavailable')

        results, runs = pages.collect_dispatches(SHA, fetch, lambda _: self.fail('Unexpected download'))
        self.assertEqual([], results)
        self.assertEqual(33, runs[0]['run_id'])
        self.assertIn('unavailable', runs[0]['status'].lower())
        report = pages.collect(SHA, ROOT, fetch, lambda _: self.fail('Unexpected download'))
        rendered = pages.render(report)
        self.assertIn('Run 33, attempt 2', rendered)
        self.assertIn(runs[0]['status'], rendered)

    def test_manual_selection_never_changes_the_27_entry_push_matrix(self):
        def fetch(include_manual):
            def response(path):
                if '/workflows/' in path:
                    if 'event=workflow_dispatch' in path:
                        return {'workflow_runs': [run('workflow_dispatch', 40)] if include_manual and 'yenc-performance.yml' in path else []}
                    return {'workflow_runs': [run()]}
                if '/artifacts?' in path:
                    return {'artifacts': []}
                return {'jobs': [{'name': 'paired-decoding (ubuntu-latest)'}]}
            return response

        baseline = pages.collect(SHA, ROOT, fetch(False), lambda _: self.fail('Unexpected download'))
        manual = pages.collect(SHA, ROOT, fetch(True), lambda _: self.fail('Unexpected download'))
        self.assertEqual(27, len(manual['results']))
        self.assertEqual(baseline['results'], manual['results'])
        self.assertEqual(baseline['workflows'], manual['workflows'])
        self.assertEqual([40], [row['run_id'] for row in manual['dispatch_results']])


if __name__ == '__main__':
    unittest.main()
