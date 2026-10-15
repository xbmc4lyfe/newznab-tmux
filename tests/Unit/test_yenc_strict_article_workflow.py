"""Exercise the isolated manual workflow's shell and evidence boundaries."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / '.github/workflows/yenc-performance.yml'
JOB = 'strict-article-experiment'
BUILD_ENV_KEYS = [
    'RUSTFLAGS', 'CARGO_ENCODED_RUSTFLAGS', 'CARGO_BUILD_RUSTFLAGS',
    'CARGO_PROFILE_RELEASE_OPT_LEVEL', 'CARGO_PROFILE_RELEASE_LTO',
    'CARGO_PROFILE_RELEASE_CODEGEN_UNITS', 'CARGO_PROFILE_RELEASE_DEBUG',
    'CARGO_PROFILE_RELEASE_DEBUG_ASSERTIONS', 'CARGO_BUILD_TARGET', 'CARGO_TARGET_DIR',
    'RUSTC', 'RUSTC_WRAPPER', 'RUSTC_WORKSPACE_WRAPPER', 'CARGO_PROFILE_RELEASE_PANIC',
]


class StrictArticleWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.workflow = WORKFLOW.read_text()

    def job(self):
        self.assertIn('\n  ' + JOB + ':\n', self.workflow, 'Missing isolated manual experiment job')
        return self.workflow.split('\n  ' + JOB + ':\n', 1)[1]

    def step(self, name):
        marker = '      - name: ' + name + '\n'
        self.assertIn(marker, self.job())
        return self.job().split(marker, 1)[1].split('      - name:', 1)[0]

    def command(self, name):
        return textwrap.dedent(self.step(name).split('        run: |\n', 1)[1])

    def shell(self, command, env, cwd, check=False):
        child = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command],
                               cwd=cwd, env=env, capture_output=True, text=True, timeout=15)
        if check:
            self.assertEqual(0, child.returncode, child.stderr)
        return child

    def reserve(self, root):
        env_file = root / 'github-env'
        env_file.touch()
        env = dict(os.environ, RUNNER_TEMP=str(root), GITHUB_RUN_ID='123', GITHUB_RUN_ATTEMPT='2',
                   GITHUB_ENV=str(env_file), STRICT_RUNNER='ubuntu-24.04-arm',
                   STRICT_SAMPLES='31', STRICT_SEED='20261013', RUST_TOOLCHAIN='1.98.0')
        self.shell(self.command('Reserve owned strict article evidence'), env, root, check=True)
        env.update(line.split('=', 1) for line in env_file.read_text().splitlines())
        return env, Path(env['STRICT_ROOT'])

    def tool(self, root, name, source):
        tools = root / 'bin'
        tools.mkdir(exist_ok=True)
        path = tools / name
        path.write_text(source)
        path.chmod(0o700)
        return tools

    @staticmethod
    def evaluate(expression, event, comparison, owned='false', evidence=''):
        expression = expression.replace('github.event_name', 'event').replace('inputs.comparison', 'comparison')
        expression = expression.replace('env.STRICT_OWNED', 'owned').replace('env.STRICT_ROOT', 'evidence')
        expression = expression.replace('always()', 'True').replace('&&', ' and ').replace('||', ' or ')
        return eval(expression, {'__builtins__': {}}, dict(event=event, comparison=comparison,
                                                         owned=owned, evidence=evidence))

    def test_manual_selection_and_concurrency_never_select_or_cancel_incumbent_jobs(self):
        job = self.job()
        predicate = re.search(r'^    if: (.+)$', job, re.MULTILINE)[1]
        self.assertIn('runner: [ubuntu-latest, ubuntu-24.04-arm]', job)
        self.assertIn('runs-on: ${{ matrix.runner }}', job)
        self.assertIn('timeout-minutes: 90', job)
        group = re.search(r'^  group: (.+)$', self.workflow, re.MULTILINE)[1]
        self.assertTrue(group.startswith('yenc-performance-${{ github.ref }}'))
        suffix = group.split('}}', 1)[1]
        self.assertTrue(suffix.startswith('${{ ') and suffix.endswith(' }}'))
        suffix = suffix[4:-3]
        choices = ['all', 'php', 'cpp', 'profile', 'processing', JOB]
        self.assertIn('options: [' + ', '.join(choices) + ']', self.workflow)
        incumbent = ['paired-decoding', 'article-processing', 'php-decoding']
        for event in ['push', 'pull_request', 'workflow_dispatch']:
            for selection in choices:
                wanted = event == 'workflow_dispatch' and selection == JOB
                self.assertEqual(wanted, bool(self.evaluate(predicate, event, selection)))
                self.assertEqual('-strict-article-experiment' if wanted else '',
                                 self.evaluate(suffix, event, selection))
                if wanted:
                    for name in incumbent:
                        body = self.workflow.split('\n  ' + name + ':\n', 1)[1]
                        condition = re.search(r'^    if: (.+)$', body, re.MULTILINE)[1]
                        self.assertFalse(self.evaluate(condition, event, selection), name)

    def test_reservation_collision_never_publishes_ownership_or_changes_existing_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env, evidence = self.reserve(root)
            self.assertEqual('true', env['STRICT_OWNED'])
            sentinel = evidence / 'sentinel'
            sentinel.write_text('unowned collision')
            (root / 'github-env').write_text('')
            before = {str(p.relative_to(evidence)): p.read_bytes() for p in evidence.rglob('*') if p.is_file()}
            failed = self.shell(self.command('Reserve owned strict article evidence'), env, root)
            self.assertNotEqual(0, failed.returncode)
            self.assertEqual('', (root / 'github-env').read_text())
            self.assertEqual(before, {str(p.relative_to(evidence)): p.read_bytes() for p in evidence.rglob('*') if p.is_file()})

    def test_phase_trap_preserves_failed_setup_logs_and_exit_status(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env, evidence = self.reserve(root)
            child = self.shell('source "$STRICT_ROOT/setup/phase.sh" broken-setup\nprintf "retained out\\n"\nprintf "retained error\\n" >&2\nexit 7', env, root)
            self.assertEqual(7, child.returncode)
            self.assertEqual('7\n', (evidence / 'setup/broken-setup.exit-code').read_text())
            self.assertEqual('broken-setup\n', (evidence / 'setup/last-phase').read_text())
            self.assertIn('retained out', (evidence / 'setup/broken-setup.stdout').read_text())
            self.assertIn('retained error', (evidence / 'setup/broken-setup.stderr').read_text())
            env['STRICT_JOB_STATUS'] = 'failure'
            self.shell(self.command('Retain strict article job status'), env, root, check=True)
            receipt = json.loads((evidence / 'job-status.json').read_text())
            self.assertEqual('failure', receipt['job_status'])
            self.assertEqual('broken-setup', receipt['last_recorded_phase'])
            self.assertEqual(7, receipt['last_phase_exit_code'])
            self.assertFalse(receipt['adoption_qualified'])

    def test_status_and_upload_require_ownership_and_do_not_publish_public_summaries(self):
        for name in ['Retain strict article job status', 'Retain strict article evidence']:
            predicate = re.search(r'^        if: (.+)$', self.step(name), re.MULTILINE)[1]
            for owned, evidence, wanted in [('false', '/tmp/other', False), ('true', '', False), ('true', '/tmp/owned', True)]:
                self.assertEqual(wanted, bool(self.evaluate(predicate, 'workflow_dispatch', JOB, owned, evidence)))
        job = self.job()
        for forbidden in ['public-benchmark-', 'export-benchmark-summary', 'yenc-performance-report',
                          'benchmark-pages', 'GITHUB_STEP_SUMMARY', 'deploy-pages']:
            self.assertNotIn(forbidden, job)
        upload = self.step('Retain strict article evidence')
        self.assertIn('yenc-strict-article-experiment-${{ matrix.runner }}-${{ github.run_id }}-${{ github.run_attempt }}', upload)
        self.assertIn('include-hidden-files: true', upload)

    def test_comparison_executes_exact_controller_contract_and_preserves_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env, evidence = self.reserve(root)
            env['GITHUB_WORKSPACE'] = str(root)
            tools = self.tool(root, 'php', '#!/usr/bin/env python3\nimport json,os,pathlib,sys\npathlib.Path(os.environ["ARGV_FILE"]).write_text(json.dumps(sys.argv[1:]))\nsys.exit(9)\n')
            self.tool(root, 'timeout', '#!/bin/sh\nshift 3\nexec "$@"\n')
            env.update(PATH=str(tools) + os.pathsep + os.environ['PATH'], ARGV_FILE=str(root / 'argv.json'))
            child = self.shell(self.command('Run isolated strict article comparison'), env, root)
            self.assertEqual(9, child.returncode)
            args = json.loads((root / 'argv.json').read_text())
            self.assertIn('scripts/benchmark-yenc-strict-article.php', args)
            for expected in ['--samples=31', '--p99-samples=2000', '--seed=20261013', '--timeout=30',
                             '--output-dir=' + str(evidence / 'comparison'),
                             '--runtime-manifest=' + str(evidence / 'setup/runtime.json'),
                             '--statistics-bin=' + str(root / 'docker/app/nzb-yenc-ffi/target/release/benchmark-strict-article-statistics')]:
                self.assertIn(expected, args)
            self.assertFalse((evidence / 'comparison').exists(), 'Controller alone must reserve its output')
            self.assertEqual('9\n', (evidence / 'setup/comparison.exit-code').read_text())

    def test_build_receipt_records_actual_feature_command_and_nonzero_build_exit(self):
        for exit_code, overrides in [(0, False), (0, True), (5, False)]:
            with self.subTest(exit_code=exit_code, overrides=overrides), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                env, evidence = self.reserve(root)
                for name in BUILD_ENV_KEYS:
                    env.pop(name, None)
                env['RUSTFLAGS'] = ''
                env['UNRELATED_REGISTRY_TOKEN'] = 'must-not-be-recorded'
                if overrides:
                    env.update(RUSTC_WRAPPER='controlled-wrapper', CARGO_PROFILE_RELEASE_OPT_LEVEL='1',
                               CARGO_BUILD_RUSTFLAGS='-C target-cpu=native')
                tools = self.tool(root, 'cargo', '#!/usr/bin/env python3\nimport json,os,pathlib,sys\nif "build" in sys.argv:\n pathlib.Path(os.environ["ARGV_FILE"]).write_text(json.dumps(["cargo",*sys.argv[1:]]))\n sys.exit(int(os.environ["BUILD_EXIT"]))\n')
                self.tool(root, 'rustup', '#!/bin/sh\nexit 0\n')
                env.update(PATH=str(tools) + os.pathsep + os.environ['PATH'], ARGV_FILE=str(root / 'argv.json'), BUILD_EXIT=str(exit_code))
                child = self.shell(self.command('Build explicit strict article experiment'), env, root)
                self.assertEqual(exit_code, child.returncode, child.stderr)
                actual = json.loads((root / 'argv.json').read_text())
                receipt = json.loads((evidence / 'setup/rust-build.json').read_text())
                self.assertEqual(actual, receipt['argv'])
                self.assertEqual(exit_code, receipt['exit_code'])
                self.assertEqual(['cargo', '+1.98.0', 'build', '--release', '--locked', '--features',
                                  'experimental-strict-article', '--manifest-path',
                                  'docker/app/nzb-yenc-ffi/Cargo.toml'], actual)
                self.assertEqual({name: env.get(name) for name in BUILD_ENV_KEYS}, receipt['environment'])
                self.assertNotIn('must-not-be-recorded', json.dumps(receipt))

    def test_native_parity_exercises_strict_and_both_incumbent_libraries_without_skips(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env, _ = self.reserve(root)
            tools = self.tool(root, 'php', '#!/usr/bin/env python3\nimport json,os,pathlib,sys\nwith pathlib.Path(os.environ["ARGV_FILE"]).open("a") as f: f.write(json.dumps({"argv":sys.argv[1:],"library":os.environ.get("YENC_NATIVE_LIBRARY")})+"\\n")\n')
            env.update(PATH=str(tools) + os.pathsep + os.environ['PATH'], ARGV_FILE=str(root / 'argv.jsonl'), GITHUB_WORKSPACE=str(root))
            self.shell(self.command('Verify strict capability and incumbent caller parity'), env, root, check=True)
            rows = [json.loads(line) for line in (root / 'argv.jsonl').read_text().splitlines()]
            self.assertEqual(3, len(rows))
            self.assertTrue(all('--fail-on-skipped' in row['argv'] for row in rows))
            self.assertIn('--filter=YencStrictArticleExperimentTest|YencStrictArticleWorkerTest', rows[0]['argv'])
            self.assertEqual(str(root / 'docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so'), rows[0]['library'])
            self.assertEqual([str(root / 'storage/app/rapidyenc/librapidyenc.so'), str(root / 'docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so')], [row['library'] for row in rows[1:]])

    def test_manifest_binds_inputs_and_retains_rejected_simd_or_build_evidence(self):
        cases = [('X64', 3, True, True, True), ('ARM64', 4, True, True, True),
                 ('X64', 2, True, True, False), ('ARM64', 3, True, True, False),
                 ('X64', 99, True, True, False), ('unknown', 3, True, True, False),
                 ('X64', 3, False, True, False), ('X64', 3, True, False, False)]
        for arch, kernel, pinned, symbol, qualified in cases:
            with self.subTest(arch=arch, kernel=kernel, pinned=pinned, symbol=symbol), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                env, evidence = self.reserve(root)
                inputs = {
                    'docker/app/nzb-yenc-ffi/src/lib.rs': b'Rust source fixture',
                    'docker/app/nzb-yenc-ffi/src/bin/statistics.rs': b'Statistics source fixture',
                    'docker/app/nzb-yenc-ffi/Cargo.toml': b'Cargo manifest fixture',
                    'docker/app/nzb-yenc-ffi/Cargo.lock': b'Cargo lock fixture',
                    'docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so': b'Rust library fixture',
                    'docker/app/nzb-yenc-ffi/target/release/benchmark-strict-article-statistics': b'Statistics binary fixture',
                    'storage/app/rapidyenc/librapidyenc.so': b'C++ library fixture',
                    'scripts/build-rapidyenc.sh': b'Pinned build script fixture',
                    'storage/app/rapidyenc/rapidyenc-480bd7b5896f8b3edecc721d23f1384d767ffe2f.tar.gz': b'controlled-pinned-archive' if pinned else b'wrong-archive',
                }
                for name, contents in inputs.items():
                    path = root / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(contents)
                build = {'argv': ['cargo', '+1.98.0', 'build', '--release', '--locked',
                                  '--features', 'experimental-strict-article'], 'exit_code': 0, 'environment': {}}
                (evidence / 'setup/rust-build.json').write_text(json.dumps(build))
                # Tool and native-loader stubs exercise the real workflow receipt writer;
                # no build, native code execution or pinned-source download is needed.
                tools = self.tool(root, 'git', '#!/bin/sh\nprintf "%040d\\n" 1\n')
                for name in ['uname', 'lscpu', 'php', 'c++', 'cmake', 'rustup']:
                    self.tool(root, name, '#!/bin/sh\nprintf "fixture tool version\\n"\n')
                modules = root / 'modules'
                modules.mkdir()
                (modules / 'ctypes.py').write_text('''import os
c_uint32 = object()
class Function:
    def __call__(self): return int(os.environ['KERNEL'])
class CDLL:
    def __init__(self, path):
        self.nntmux_yenc_payload_kernel_v1 = Function()
        if os.environ['STRICT_SYMBOL'] == 'yes':
            self.nntmux_yenc_decode_strict_article_v1 = Function()
''')
                # Map one archive fixture to its pinned digest; all other inputs use
                # the standard SHA-256 implementation and independently checked bytes.
                (modules / 'hashlib.py').write_text('''import _hashlib
def sha256(data):
    if data == b'controlled-pinned-archive':
        class Pinned:
            def hexdigest(self): return 'bd5eff1e978672af4ebaacde837270a679808bbc12dc15bd6acf7c83d94baa16'
        return Pinned()
    return _hashlib.openssl_sha256(data)
''')
                env.update(PATH=str(tools) + os.pathsep + os.environ['PATH'], PYTHONPATH=str(modules),
                           RUNNER_ARCH=arch, KERNEL=str(kernel), STRICT_SYMBOL='yes' if symbol else 'no')
                child = self.shell(self.command('Record strict article runtime and require designated SIMD'), env, root)
                self.assertEqual(qualified, child.returncode == 0, child.stderr)
                manifest = json.loads((evidence / 'setup/runtime.json').read_text())
                gate = json.loads((evidence / 'setup/hardware-qualification.json').read_text())
                self.assertEqual('qualified' if qualified else 'unsupported_hardware_or_build', gate['status'])
                self.assertEqual(kernel, gate['observed_kernel']['id'])
                self.assertFalse(gate['adoption_qualified'])
                self.assertEqual('incumbent', manifest['rust_payload_mode'])
                self.assertEqual('experimental', manifest['rust_strict_article_mode'])
                self.assertEqual(['experimental-strict-article'], manifest['rust_features'])
                self.assertEqual('0' * 39 + '1', manifest['git_head'])
                self.assertEqual(build, manifest['rust_build_receipt'])
                sources = {name: hashlib.sha256(data).hexdigest() for name, data in inputs.items() if '/src/' in name}
                self.assertEqual(sources, manifest['rust_source_sha256'])
                for key, name in [
                    ('cargo_toml_sha256', 'docker/app/nzb-yenc-ffi/Cargo.toml'),
                    ('cargo_lock_sha256', 'docker/app/nzb-yenc-ffi/Cargo.lock'),
                    ('cpp_build_script_sha256', 'scripts/build-rapidyenc.sh'),
                    ('rust_library_sha256', 'docker/app/nzb-yenc-ffi/target/release/libnzbyenc.so'),
                    ('cpp_library_sha256', 'storage/app/rapidyenc/librapidyenc.so'),
                    ('statistics_binary_sha256', 'docker/app/nzb-yenc-ffi/target/release/benchmark-strict-article-statistics'),
                ]:
                    self.assertEqual(hashlib.sha256(inputs[name]).hexdigest(), manifest[key], key)
                self.assertEqual('diagnostic_endpoints_only', manifest['cpu_context_scope'])
                self.assertFalse(manifest['adoption_qualified'])

    def test_cpu_diagnostics_retain_distinct_endpoints_even_after_failed_comparison(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env, evidence = self.reserve(root)
            executable = root / 'docker/app/nzb-yenc-ffi/target/release/nntmux-cpu-snapshot'
            executable.parent.mkdir(parents=True)
            executable.write_text('#!/usr/bin/env python3\nimport json,pathlib,sys\npathlib.Path(sys.argv[2]).write_text(json.dumps({"diagnostic":sys.argv[2]}))\n')
            executable.chmod(0o700)
            before = 'Capture diagnostic CPU endpoint before strict article comparison'
            after = 'Capture diagnostic CPU endpoint after strict article comparison'
            self.shell(self.command(after), env, root, check=True)
            self.assertFalse((evidence / 'setup/cpu-after.json').exists())
            self.shell(self.command(before), env, root, check=True)
            self.shell('source "$STRICT_ROOT/setup/phase.sh" comparison\nexit 9', env, root)
            self.shell(self.command(after), env, root, check=True)
            self.assertTrue((evidence / 'setup/cpu-before.json').is_file())
            self.assertTrue((evidence / 'setup/cpu-after.json').is_file())
            self.assertEqual('9\n', (evidence / 'setup/comparison.exit-code').read_text())


if __name__ == '__main__':
    unittest.main()
