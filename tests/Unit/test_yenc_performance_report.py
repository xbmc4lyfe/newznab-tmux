import importlib.util
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'scripts' / 'yenc-performance-report.py'


class PerformanceReportTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(SCRIPT.is_file(), 'Performance report renderer must exist')
        spec = importlib.util.spec_from_file_location('performance_report', SCRIPT)
        self.report = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.report)

    def test_ratio_inversion_and_time_saved_use_different_units(self):
        row = {'decision': 'useful_improvement', 'median_rust_over_cpp': 0.8,
               'bootstrap_95_percent_interval': [0.75, 0.9], 'invalid_pairs': 0}
        metrics = self.report.comparison(row)
        self.assertAlmostEqual(1.25, metrics['speedup'])
        self.assertAlmostEqual(20, metrics['time_saved_percent'])
        self.assertAlmostEqual(1 / 0.9, metrics['speedup_interval'][0])
        self.assertAlmostEqual(1 / 0.75, metrics['speedup_interval'][1])

    def test_cpu_clock_weights_are_nanoseconds_and_not_sample_counts(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'native.folded').write_text('php;decode 4008016\n')
            (path / 'perf.stacks').write_text('php 42 1.123456: 2004008 cpu-clock:\n\t123 decode (/lib.so)\n\n'
                                            'php 42 1.125460: 2004008 cpu-clock:\n\t123 decode (/lib.so)\n\n')
            result = self.report.native_measurements(path)
            self.assertEqual(2, result['sample_records'])
            self.assertEqual(4008016, result['folded_cpu_clock_nanoseconds'])

    def test_invalid_nonfinite_or_incomplete_results_never_get_a_gain(self):
        for row in [
            {'decision': 'invalid', 'median_rust_over_cpp': 0.1},
            {'decision': 'inconclusive', 'median_rust_over_cpp': 0},
            {'decision': 'inconclusive', 'median_rust_over_cpp': float('nan')},
            {'decision': 'useful_improvement', 'median_rust_over_cpp': 0.8,
             'bootstrap_95_percent_interval': [0.7, 0.9], 'invalid_pairs': 1},
            {'decision': 'inconclusive', 'median_rust_over_cpp': 0.8},
        ]:
            self.assertIsNone(self.report.comparison(row))

    def test_regression_keeps_negative_time_savings(self):
        metrics = self.report.comparison({'decision': 'regression', 'median_rust_over_cpp': 1.2,
                                         'bootstrap_95_percent_interval': [1.1, 1.3], 'invalid_pairs': 0})
        self.assertLess(metrics['speedup'], 1)
        self.assertAlmostEqual(-20, metrics['time_saved_percent'])

    def test_callgrind_distinguishes_self_cost_from_inclusive_call_edges(self):
        profile = '''version: 1
events: Time_(10ns) Memory
fl=(1) /app/main.php
fn=(1) main
1 20 0
cfl=(2) /app/decoder.php
cfn=(2) decode
calls=3 10
2 80 0
fl=(2)
fn=(2)
10 30 0
cfl=(2)
cfn=(2)
calls=2 10
11 50 0
'''
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'profile.callgrind'
            path.write_text(profile)
            result = self.report.read_callgrind(path)
        self.assertEqual(50, result['total_self_cost'])
        self.assertEqual(20, result['functions'][('/app/main.php', 'main')])
        self.assertEqual(30, result['functions'][('/app/decoder.php', 'decode')])
        edge = result['edges'][(('/app/main.php', 'main'), ('/app/decoder.php', 'decode'))]
        self.assertEqual({'calls': 3, 'inclusive_cost': 80}, edge)

    def test_unknown_compressed_names_and_wrong_event_type_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'bad.callgrind'
            for profile in ['events: Instructions\nfn=main\n1 5\n',
                            'events: Time_(10ns)\nfl=(7)\nfn=main\n1 5\n']:
                path.write_text(profile)
                with self.assertRaises(ValueError):
                    self.report.read_callgrind(path)

    def test_svg_escapes_profile_names(self):
        svg = self.report.bar_svg('a < b', [('decode<script>', 4.0)], 'self cost')
        self.assertNotIn('<script>', svg)
        self.assertIn('decode&lt;script&gt;', svg)

    def test_failed_or_unmatched_workload_receipts_cannot_produce_valid_hotpaths(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = Path(directory)
            profile_dir = evidence / 'profiles' / 'cpp-strict'
            profile_dir.mkdir(parents=True)
            profile = profile_dir / 'php.callgrind'
            profile.write_text('events: Time_(10ns)\nfl=/app.php\nfn=decode\n1 200\n')
            receipt = {'php_profile': 'captured', 'php_profile_sha256': hashlib.sha256(profile.read_bytes()).hexdigest()}
            (profile_dir / 'status.json').write_text(json.dumps(receipt))
            workload = {'status': 'ok', 'verified_calls': 3, 'iterations': 2, 'warmups': 1}
            for bad in [dict(workload, status='invalid'), dict(workload, verified_calls=2)]:
                (profile_dir / 'php-workload.json').write_text(json.dumps(bad))
                output = evidence / 'visualization'
                self.report.render(evidence, output)
                self.assertEqual('invalid', json.loads((output / 'status.json').read_text())['profiles'][0]['status'])
                self.assertFalse((output / 'hotpaths-0.svg').exists())
            (profile_dir / 'php-workload.json').write_text(json.dumps(workload))
            self.report.render(evidence, output)
            self.assertEqual('ok', json.loads((output / 'status.json').read_text())['profiles'][0]['status'])
            self.assertTrue((output / 'hotpaths-0.svg').exists())
            profile.write_text(profile.read_text() + '2 100\n')
            self.report.render(evidence, output)
            self.assertEqual('invalid', json.loads((output / 'status.json').read_text())['profiles'][0]['status'])
            self.assertFalse((output / 'hotpaths-0.svg').exists())

    def test_profile_status_retains_postprocessing_and_preflight_failures(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            for code, cause in [(124, 'timeout'), (127, 'invocation_failure'), (139, 'signal'), (255, 'nonzero_exit'), (1, 'nonzero_exit')]:
                self.assertEqual(cause, self.report.exit_cause(code))
            status = self.report.profile_status(path, 'unavailable', 0, 0, 'not_run', 124)
            self.assertEqual(124, status['perf_preflight_exit'])
            self.assertEqual('timeout', status['perf_preflight_cause'])
            self.assertEqual('invalid', status['native_profile'])
            status = self.report.profile_status(path, 'unavailable', 0, 0, 'not_run', 1)
            self.assertEqual('unavailable', status['native_profile'])
            status = self.report.profile_status(path, 'unavailable', 0, 0, 'not_run', 255)
            self.assertEqual('unavailable', status['native_profile'])
            self.assertEqual(255, status['perf_preflight_exit'])
            status = self.report.profile_status(path, 139, 0, 0, 'not_run', 0)
            self.assertEqual('invalid', status['native_profile'])
            self.assertEqual('signal', status['native_exit_cause'])
            status = self.report.profile_status(path, 0, 0, 7, 'perf-script', 0)
            self.assertEqual('invalid', status['native_profile'])
            self.assertEqual(7, status['native_postprocess_exit'])
            self.assertEqual('perf-script', status['native_postprocess_stage'])
            self.assertEqual('timeout', self.report.exit_cause(137, timed_out=True))
            self.assertEqual('signal', self.report.exit_cause(137, timed_out=False))
            (path / 'perf.stderr').write_text('timeout: sending signal TERM to command env\ntimeout: sending signal KILL to command env\n')
            status = self.report.profile_status(path, 137, 0, 0, 'not_run', 0)
            self.assertTrue(status['native_timed_out'])
            self.assertEqual('timeout', status['native_exit_cause'])

    def test_setup_failure_receipt_is_visible_without_any_workload_capture(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = Path(directory)
            (evidence / 'profiles').mkdir()
            (evidence / 'profiles' / 'status.json').write_text(json.dumps({'status': 'invalid', 'phase': 'apt-update', 'exit_code': 100}))
            output = evidence / 'visualization'
            self.report.render(evidence, output)
            status = json.loads((output / 'status.json').read_text())
            self.assertEqual('apt-update', status['profiling_setup']['phase'])
            self.assertEqual(100, status['profiling_setup']['exit_code'])
            self.assertIn('apt-update', (output / 'report.html').read_text())

    def test_workflow_records_actual_package_setup_failure_before_any_capture(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        step = workflow.split('      - name: Capture executed PHP and native hot paths\n', 1)[1].split('      - name:', 1)[0]
        command = textwrap.dedent(step.split('        run: |\n', 1)[1])
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'bin').mkdir()
            sudo = root / 'bin/sudo'
            sudo.write_text('#!/bin/sh\necho fixture-package-failure >&2\nexit 100\n')
            sudo.chmod(0o700)
            (root / 'benchmark-evidence/build').mkdir(parents=True)
            (root / 'benchmark-evidence/build/runtime.json').write_text('{}')
            environment = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'])
            child = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command], cwd=root,
                                   env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(100, child.returncode)
            receipt = json.loads((root / 'benchmark-evidence/profiles/status.json').read_text())
            self.assertEqual({'status': 'invalid', 'exit_code': 100, 'phase': 'apt-update'}, receipt)

    @unittest.skipUnless(sys.platform.startswith('linux') and shutil.which('timeout'), 'GNU timeout integration runs in Linux CI')
    def test_forced_kill_from_real_timeout_retains_timeout_cause(self):
        child = subprocess.run(['timeout', '--verbose', '--kill-after=0.1s', '0.2s', sys.executable, '-c',
                                'import signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); time.sleep(5)'],
                               env=dict(os.environ, LC_ALL='C'), capture_output=True, text=True, timeout=3)
        code = 128 - child.returncode if child.returncode < 0 else child.returncode
        self.assertEqual(137, code)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / 'perf.stderr').write_text(child.stderr)
            status = self.report.profile_status(path, code, 0, 0, 'not_run', 0)
            self.assertTrue(status['native_timed_out'])
            self.assertEqual('timeout', status['native_exit_cause'])

    def test_missing_php_capture_is_reported_even_when_another_workload_has_one(self):
        with tempfile.TemporaryDirectory() as directory:
            evidence = Path(directory)
            path = evidence / 'profiles' / 'missing'
            path.mkdir(parents=True)
            (path / 'status.json').write_text(json.dumps({'php_profile': 'invalid'}))
            (path / 'php-workload.json').write_text(json.dumps({'status': 'invalid'}))
            other = evidence / 'profiles' / 'partial'
            other.mkdir()
            (other / 'php.callgrind').write_text('events: Time_(10ns)\nfl=/app.php\nfn=decode\n1 200\n')
            output = evidence / 'visualization'
            self.report.render(evidence, output)
            profiles = json.loads((output / 'status.json').read_text())['profiles']
            self.assertEqual(2, len(profiles))
            self.assertTrue(all(profile['status'] == 'invalid' for profile in profiles))
            self.assertIn('profiles/missing/php.callgrind', (output / 'report.html').read_text())


if __name__ == '__main__':
    unittest.main()
