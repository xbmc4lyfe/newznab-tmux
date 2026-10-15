import ast
import importlib.util
import hashlib
import json
import os
import re
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


class ProfileBuildTest(unittest.TestCase):
    @unittest.skipUnless(shutil.which('cc'), 'Native identity fixture needs the existing C compiler')
    def test_profile_build_retains_selected_mode_command_and_actual_library_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            shutil.copyfile(SCRIPT.with_name('profile-yenc.sh'), root / 'scripts/profile-yenc.sh')
            tools = root / 'bin'
            tools.mkdir()
            library = tools / 'kernel.so'
            subprocess.run([shutil.which('cc'), '-shared', '-fPIC', '-x', 'c', '-', '-o', str(library)],
                           input='unsigned nntmux_yenc_payload_kernel_v1(void) { return 4; }',
                           text=True, check=True, capture_output=True)
            dispatcher = tools / 'dispatcher'
            dispatcher.write_text(f'#!{sys.executable}\n' + textwrap.dedent('''\
                import json, os, pathlib, shutil, sys
                name = pathlib.Path(sys.argv[0]).name
                args = sys.argv[1:]
                if name == 'uname':
                    print('Linux')
                elif name == 'cmake' and args[0] == '--build':
                    target = pathlib.Path(args[1])
                    target.mkdir(parents=True, exist_ok=True)
                    (target / 'librapidyenc.so').write_bytes(b'fixture-cpp')
                elif name == 'cargo':
                    target = pathlib.Path(os.environ['CARGO_TARGET_DIR'])
                    (target / 'release').mkdir(parents=True)
                    shutil.copyfile(pathlib.Path(__file__).resolve().parent / 'kernel.so',
                                    target / 'release/libnzbyenc.so')
                    (target.parent / 'cargo-argv.json').write_text(json.dumps(['cargo', *args]))
                elif name == 'git':
                    sys.exit(42)  # Stop after build custody, before instrumentation.
                elif name not in ('sha256sum', 'tar', 'cmake'):
                    sys.exit(99)
                '''))
            dispatcher.chmod(0o700)
            for name in ('uname', 'cmake', 'cargo', 'git', 'sha256sum', 'tar'):
                (tools / name).symlink_to(dispatcher)
            perf = tools / 'perf'
            perf.touch()
            flamegraph = root / 'flamegraph'
            flamegraph.mkdir()
            environment = {'PATH': str(tools) + os.pathsep + os.environ['PATH'], 'RUST_TOOLCHAIN': '1.98.0'}
            for supplied_mode in ('experimental', 'incumbent', None):
                with self.subTest(mode=supplied_mode):
                    evidence = root / ('evidence-' + str(supplied_mode))
                    evidence.mkdir()
                    command = ['bash', str(root / 'scripts/profile-yenc.sh'), str(evidence),
                               str(flamegraph), str(perf)]
                    if supplied_mode is not None:
                        command.append(supplied_mode)
                    child = subprocess.run(command, env=environment, capture_output=True, text=True, timeout=10)
                    self.assertEqual(42, child.returncode, child.stderr)
                    mode = supplied_mode or 'incumbent'
                    args = json.loads((evidence / 'profile-build/cargo-argv.json').read_text())
                    if mode == 'experimental':
                        self.assertEqual('experimental-payload-kernels', args[args.index('--features') + 1])
                    else:
                        self.assertNotIn('--features', args)
                    receipt_path = evidence / 'profile-build/rust-build.json'
                    self.assertTrue(receipt_path.is_file(), 'Missing payload build custody receipt')
                    receipt = json.loads(receipt_path.read_text())
                    self.assertEqual(mode, receipt['payload_profile'])
                    self.assertEqual(args, receipt['command'])
                    self.assertEqual(4, receipt['runtime_kernel_id'])
                    self.assertEqual(hashlib.sha256(library.read_bytes()).hexdigest(), receipt['library_sha256'])
                    self.assertEqual(str((evidence / 'profile-build/rust/release/libnzbyenc.so').resolve()), receipt['library'])
                    status = json.loads((evidence / 'profiles/status.json').read_text())
                    self.assertEqual(mode, status['rust_payload_profile'])
                    self.assertEqual(receipt, status['rust_build_receipt'])
            for invalid_mode in ('unknown', '--features=experimental-payload-kernels'):
                evidence = root / ('invalid-' + invalid_mode.replace('/', '_'))
                evidence.mkdir()
                child = subprocess.run(['bash', str(root / 'scripts/profile-yenc.sh'), str(evidence),
                                        str(flamegraph), str(perf), invalid_mode], env=environment,
                                       capture_output=True, text=True, timeout=10)
                self.assertEqual(2, child.returncode)
                self.assertFalse((evidence / 'profile-build').exists())

    def test_rust_aa_control_job_is_manual_isolated_and_retains_failure_evidence(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        self.assertIn('options: [all, php, cpp, profile, processing, rust-aa-control]', workflow)
        job = workflow.split('  rust-aa-control:\n', 1)[1]
        self.assertIn("if: github.event_name == 'workflow_dispatch' && inputs.comparison == 'rust-aa-control'", job)
        self.assertIn('runs-on: ubuntu-24.04-arm', job)
        self.assertNotIn('export-benchmark-summary.py', job)
        self.assertNotIn('public-benchmark-', job)
        self.assertIn('name: yenc-abi-rust-aa-control-', job)
        upload = job.split('      - name: Retain all isolated Rust control evidence\n', 1)[1]
        self.assertIn("if: always() && env.CONTROL_RESERVED == 'true' && env.CONTROL_ROOT != ''", upload)
        self.assertIn('if: always()', job)
        self.assertIn('include-hidden-files: true', job)
        self.assertIn('--features experimental-payload-kernels', job)
        self.assertIn('--samples=1', job)
        self.assertIn('--iterations=3', job)
        self.assertIn('--worker=$GITHUB_WORKSPACE/scripts/benchmark-yenc-abi.py', job)
        self.assertIn('--source-root=$GITHUB_WORKSPACE', job)
        self.assertIn('A/A control only; no C++ comparison or adoption verdict', job)

    def test_control_reservation_publishes_ownership_only_after_success(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        job = workflow.split('  rust-aa-control:\n', 1)[1]
        step = job.split('      - name: Reserve separate owned control evidence\n', 1)[1].split('      - ', 1)[0]
        command = textwrap.dedent(step.split('        run: |\n', 1)[1])
        for collision in [False, True]:
            with self.subTest(collision=collision), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                evidence = root / 'nntmux-rust-aa-123-1'
                env_file = root / 'github-env'
                env_file.touch()
                if collision:
                    evidence.mkdir()
                    (evidence / 'sentinel').write_text('unowned')
                env = dict(os.environ, RUNNER_TEMP=str(root), GITHUB_RUN_ID='123',
                           GITHUB_RUN_ATTEMPT='1', GITHUB_ENV=str(env_file))
                child = subprocess.run(['bash', '-e', '-c', command], env=env,
                                       capture_output=True, text=True, timeout=10)
                if collision:
                    self.assertNotEqual(0, child.returncode)
                    self.assertEqual('', env_file.read_text())
                    self.assertEqual(['sentinel'], sorted(p.name for p in evidence.iterdir()))
                else:
                    self.assertEqual(0, child.returncode, child.stderr)
                    self.assertIn('CONTROL_RESERVED=true\n', env_file.read_text())
                    self.assertIn('CONTROL_ROOT=' + str(evidence) + '\n', env_file.read_text())
        guard = "if: always() && env.CONTROL_RESERVED == 'true' && env.CONTROL_ROOT != ''"
        for name in ['Retain the last observed setup and execution status', 'Retain all isolated Rust control evidence']:
            block = job.split('      - name: ' + name + '\n', 1)[1].split('      - name:', 1)[0]
            self.assertIn(guard, block)

    def test_manual_control_selection_and_concurrency_leave_existing_jobs_unchanged(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        predicates = {
            'paired-decoding': "github.event_name != 'workflow_dispatch' || inputs.comparison == 'all' || inputs.comparison == 'cpp'",
            'article-processing': "github.event_name != 'workflow_dispatch' || inputs.comparison == 'all' || inputs.comparison == 'profile' || inputs.comparison == 'processing'",
            'php-decoding': "github.event_name != 'workflow_dispatch' || inputs.comparison == 'all' || inputs.comparison == 'php'",
            'rust-aa-control': "github.event_name == 'workflow_dispatch' && inputs.comparison == 'rust-aa-control'",
        }
        for name, predicate in predicates.items():
            block = workflow.split('  ' + name + ':\n', 1)[1]
            self.assertEqual(predicate, re.search(r'^    if: (.+)$', block, re.MULTILINE)[1])
        suffix = "github.event_name == 'workflow_dispatch' && inputs.comparison == 'rust-aa-control' && '-rust-aa-control' || ''"
        prefix = 'group: yenc-performance-${{ github.ref }}-${{ github.event_name }}'
        self.assertIn(prefix + '${{ ' + suffix + ' }}', workflow)
        concurrency_keys = set()
        for event in ['pull_request', 'push', 'workflow_dispatch']:
            for selection in ['all', 'php', 'cpp', 'profile', 'processing', 'rust-aa-control']:
                context = {'event_name': event, 'comparison': selection}
                def evaluate(expression):
                    expression = expression.replace('github.event_name', 'event_name').replace('inputs.comparison', 'comparison')
                    return eval(expression.replace('&&', ' and ').replace('||', ' or '), {'__builtins__': {}}, context)
                enabled = [name for name, predicate in predicates.items() if evaluate(predicate)]
                if event != 'workflow_dispatch':
                    self.assertEqual(list(predicates)[:3], enabled)
                elif selection == 'rust-aa-control':
                    self.assertEqual(['rust-aa-control'], enabled)
                else:
                    self.assertNotIn('rust-aa-control', enabled)
                suffix_value = evaluate(suffix)
                self.assertEqual('-rust-aa-control' if event == 'workflow_dispatch' and selection == 'rust-aa-control' else '', suffix_value)
                if ((event, selection) in [('push', 'all'), ('pull_request', 'all'),
                                          ('workflow_dispatch', 'all'), ('workflow_dispatch', 'rust-aa-control')]):
                    concurrency_keys.add('yenc-performance-refs/heads/master-' + event + suffix_value)
        self.assertEqual(4, len(concurrency_keys))

    def test_manual_control_wrapper_retains_integration_and_control_failures(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        job = workflow.split('  rust-aa-control:\n', 1)[1]
        step = job.split('      - name: Exercise the full CLI through the real frozen worker then collect controls\n', 1)[1].split('      - name:', 1)[0]
        command = textwrap.dedent(step.split('        run: |\n', 1)[1])
        status_step = job.split('      - name: Retain the last observed setup and execution status\n', 1)[1].split('      - name:', 1)[0]
        status_command = textwrap.dedent(status_step.split('        run: |\n', 1)[1])
        source = (SCRIPT.parents[1] / 'docker/app/nzb-yenc-ffi/src/bin/benchmark-yenc-control.rs').read_text()
        self.assertIn('fn real_linux_cli_retains_full_worker_evidence_and_rejects_invalid_inputs()', source)
        for integration_code, control_code in [(12, 0), (0, 7), (0, 0)]:
            with self.subTest(integration=integration_code, control=control_code), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                evidence = root / 'evidence'
                (evidence / 'setup').mkdir(parents=True)
                (root / 'bin').mkdir()
                for name, script in {
                    'cargo': '#!/bin/sh\nexit "$FIXTURE_INTEGRATION_CODE"\n',
                    'timeout': '#!/bin/sh\nshift 3\nexec "$@"\n',
                }.items():
                    tool = root / 'bin' / name
                    tool.write_text(script)
                    tool.chmod(0o700)
                controller = root / 'docker/app/nzb-yenc-ffi/target/release/benchmark-yenc-control'
                controller.parent.mkdir(parents=True)
                controller.write_text('#!/bin/sh\nprintf "%s\\n" fixture-control\nexit "$FIXTURE_CONTROL_CODE"\n')
                controller.chmod(0o700)
                env = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'],
                           GITHUB_WORKSPACE=str(root), CONTROL_ROOT=str(evidence), CONTROL_SAMPLES='3',
                           CONTROL_SEED='20261015', RUST_TOOLCHAIN='1.98.0',
                           FIXTURE_INTEGRATION_CODE=str(integration_code), FIXTURE_CONTROL_CODE=str(control_code))
                child = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command], env=env,
                                       capture_output=True, text=True, timeout=10)
                self.assertEqual(integration_code or control_code, child.returncode)
                env['CONTROL_JOB_STATUS'] = 'failure' if child.returncode else 'success'
                receipt = subprocess.run(['bash', '-e', '-c', status_command], env=env,
                                         capture_output=True, text=True, timeout=10)
                self.assertEqual(0, receipt.returncode, receipt.stderr)
                status = json.loads((evidence / 'job-status.json').read_text())
                self.assertEqual('failure' if child.returncode else 'success', status['job_status'])
                self.assertEqual('cli-integration' if integration_code else 'control' if control_code else 'complete', status['last_recorded_phase'])
                self.assertEqual(None if integration_code else control_code, status['control_exit_code'])
                self.assertFalse(status['adoption_qualified'])
                self.assertFalse((evidence / 'control/cpu-before.json').exists())

    def test_runtime_manifests_declare_one_unambiguous_payload_mode(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        modes = []
        blocks = re.finditer(
            r"^ {10}python3[^\n]* <<'(?P<marker>PY[^']*)'\n(?P<body>.*?)^ {10}(?P=marker)$",
            workflow, re.MULTILINE | re.DOTALL)
        for block in blocks:
            tree = ast.parse(textwrap.dedent(block['body']))
            for node in ast.walk(tree):
                if not isinstance(node, ast.Dict):
                    continue
                values = [value for key, value in zip(node.keys, node.values)
                          if isinstance(key, ast.Constant) and key.value == 'rust_payload_mode']
                if values:
                    self.assertEqual(1, len(values), 'Duplicate payload-mode declarations')
                    modes.append(ast.literal_eval(values[0]))
        self.assertEqual(['experimental', 'incumbent', 'incumbent', 'experimental'], modes)

    def test_experimental_workflow_passes_its_payload_mode_to_the_profiling_handler(self):
        workflow = (SCRIPT.parents[1] / '.github/workflows/yenc-performance.yml').read_text()
        step = workflow.split('      - name: Capture executed PHP and native hot paths\n', 1)[1].split('      - name:', 1)[0]
        command = textwrap.dedent(step.split('        run: |\n', 1)[1])
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'bin').mkdir()
            for name in ('sudo', 'git'):
                tool = root / 'bin' / name
                tool.write_text('#!/bin/sh\nexit 0\n')
                tool.chmod(0o700)
            find = root / 'bin/find'
            find.write_text('#!/bin/sh\nprintf "%s\\n" "$GITHUB_WORKSPACE/bin/perf"\n')
            find.chmod(0o700)
            (root / 'scripts').mkdir()
            handler = root / 'scripts/profile-yenc.sh'
            handler.write_text('#!/bin/sh\nprintf "%s\\n" "$@" > "$GITHUB_WORKSPACE/profile-argv"\n'
                               'printf \'{"status":"complete"}\\n\' > "$GITHUB_WORKSPACE/benchmark-evidence/profiles/status.json"\n')
            (root / 'benchmark-evidence/build').mkdir(parents=True)
            (root / 'benchmark-evidence/build/runtime.json').write_text('{}')
            environment = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'],
                               GITHUB_WORKSPACE=str(root), RUNNER_TEMP=str(root))
            child = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command], cwd=root,
                                   env=environment, capture_output=True, text=True, timeout=10)
            self.assertEqual(0, child.returncode, child.stderr)
            self.assertEqual([str(root / 'benchmark-evidence'), str(root / 'nntmux-flamegraph'),
                              str(root / 'bin/perf'), 'experimental'],
                             (root / 'profile-argv').read_text().splitlines())


if __name__ == '__main__':
    unittest.main()
