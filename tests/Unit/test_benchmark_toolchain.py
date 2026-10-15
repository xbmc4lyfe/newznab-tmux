import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / 'scripts/benchmark-rust-toolchain.sh'
TOOLCHAIN = '1.98.0'


class BenchmarkToolchainTest(unittest.TestCase):
    def run_helper(self, *, installed='', install_status=0, broken_after_install='', path_fallback=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shared = root / 'shared-rustup'
            shared.mkdir()
            runner_temp = root / 'runner-temp'
            runner_temp.mkdir()
            rustup = root / 'rustup'
            rustup.write_text(f'#!{sys.executable}\n' + '''
import json
import os
from pathlib import Path
import sys

args = sys.argv[1:]
root = Path(__file__).parent
home = Path(os.environ['RUSTUP_HOME'])
with (root / 'calls.jsonl').open('a') as log:
    log.write(json.dumps({'args': args, 'rustup_home': str(home),
                          'auto_install': os.environ.get('RUSTUP_AUTO_INSTALL')}) + '\\n')
if args[:2] == ['toolchain', 'install']:
    if home == root / 'shared-rustup':
        print('read-only shared toolchain store', file=sys.stderr)
        raise SystemExit(30)
    if int(os.environ['INSTALL_STATUS']):
        print('toolchain download failed', file=sys.stderr)
        raise SystemExit(int(os.environ['INSTALL_STATUS']))
    (home / 'installed').touch()
elif len(args) == 4 and (args[0] == 'run' and args[3] == '-V' or args[:2] == ['which', '--toolchain']):
    tool = args[2] if args[0] == 'run' else args[3]
    available = ((home == root / 'shared-rustup' and tool in os.environ['INSTALLED'].split(',')) or
                 ((home / 'installed').exists() and tool != os.environ['BROKEN_AFTER_INSTALL']))
    if not available and not (args[0] == 'run' and os.environ['PATH_FALLBACK'] == '1'):
        raise SystemExit(1)
    print(tool + ' ' + args[1] + ' (fixture)')
else:
    raise SystemExit(99)
''')
            rustup.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'],
                       RUST_TOOLCHAIN=TOOLCHAIN, INSTALLED=installed,
                       INSTALL_STATUS=str(install_status), BROKEN_AFTER_INSTALL=broken_after_install,
                       PATH_FALLBACK='1' if path_fallback else '0',
                       RUSTUP_HOME=str(shared), RUNNER_TEMP=str(runner_temp),
                       GITHUB_ENV=str(root / 'github-env'), CARGO_HOME=str(root / 'existing-cargo'),
                       CALLER_STATE=str(root / 'caller-state'))
            command = 'source "$1"\nprintf "%s\\n" "$RUSTUP_HOME" "$CARGO_HOME" > "$CALLER_STATE"'
            result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command, 'setup', str(SCRIPT)],
                                    env=env, text=True, capture_output=True)
            log = root / 'calls.jsonl'
            records = [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
            state = root / 'caller-state'
            github_env = root / 'github-env'
            evidence = dict(shared=str(shared), runner_temp=str(runner_temp),
                            cargo_home=env['CARGO_HOME'], records=records,
                            caller_state=state.read_text().splitlines() if state.exists() else [],
                            github_env=github_env.read_text() if github_env.exists() else '')
            if evidence['github_env']:
                later_env = dict(env, **dict(line.split('=', 1) for line in evidence['github_env'].splitlines()))
                evidence['later_step'] = subprocess.run(
                    ['rustup', 'run', TOOLCHAIN, 'cargo', '-V'], env=later_env, text=True, capture_output=True)
            return result, [record['args'] for record in records], evidence

    def test_path_fallback_cannot_satisfy_a_missing_pinned_binary(self):
        for installed in ('rustc', 'cargo'):
            with self.subTest(installed=installed):
                result, calls, evidence = self.run_helper(installed=installed, path_fallback=True)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertTrue(any(call[:2] == ['toolchain', 'install'] for call in calls))
                self.assertNotEqual(evidence['shared'], evidence['caller_state'][0])
                self.assertTrue(all(record['auto_install'] == '0' for record in evidence['records']
                                    if record['args'][0] == 'which'))

    def test_path_fallback_cannot_hide_a_broken_post_installation(self):
        for tool in ('rustc', 'cargo'):
            with self.subTest(tool=tool):
                result, calls, evidence = self.run_helper(broken_after_install=tool, path_fallback=True)
                self.assertNotEqual(0, result.returncode)
                self.assertTrue(any(call[:2] == ['toolchain', 'install'] for call in calls))
                self.assertEqual('', evidence['github_env'])
                self.assertEqual([], evidence['caller_state'])

    def test_reuses_both_pinned_tools_when_install_would_fail_on_read_only_store(self):
        result, calls, evidence = self.run_helper(installed='rustc,cargo', install_status=30)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['which', '--toolchain', '1.98.0', 'cargo'],
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)
        self.assertEqual([evidence['shared'], evidence['cargo_home']], evidence['caller_state'])
        self.assertEqual('', evidence['github_env'])

    def test_installs_missing_exact_toolchain_then_checks_both_tools(self):
        result, calls, _ = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal', '--no-self-update'],
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['which', '--toolchain', '1.98.0', 'cargo'],
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)

    def test_missing_cargo_does_not_accept_a_rustc_only_installation(self):
        result, calls, _ = self.run_helper(installed='rustc')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['which', '--toolchain', '1.98.0', 'cargo'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal', '--no-self-update'],
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['which', '--toolchain', '1.98.0', 'cargo'],
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)

    def test_install_failure_is_propagated_without_running_build_tools(self):
        result, calls, evidence = self.run_helper(install_status=42)
        self.assertEqual(42, result.returncode, result.stderr)
        self.assertEqual([
            ['which', '--toolchain', '1.98.0', 'rustc'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal', '--no-self-update'],
        ], calls)
        self.assertEqual('', evidence['github_env'])
        self.assertEqual([], evidence['caller_state'])

    def test_successful_install_still_requires_working_rustc_and_cargo(self):
        for tool in ('rustc', 'cargo'):
            with self.subTest(tool=tool):
                result, calls, evidence = self.run_helper(broken_after_install=tool)
                self.assertNotEqual(0, result.returncode)
                self.assertTrue(calls)
                self.assertEqual(['which', '--toolchain', '1.98.0', tool], calls[-1])
                self.assertEqual('', evidence['github_env'])

    def test_missing_pin_uses_a_fresh_job_store_visible_to_current_and_later_steps(self):
        result, _, evidence = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        selected = evidence['caller_state'][0]
        self.assertEqual(evidence['runner_temp'], str(Path(selected).parent))
        self.assertNotEqual(evidence['shared'], selected)
        self.assertEqual(evidence['cargo_home'], evidence['caller_state'][1])
        self.assertEqual('RUSTUP_HOME=' + selected + '\n', evidence['github_env'])
        self.assertEqual(0, evidence['later_step'].returncode, evidence['later_step'].stderr)
        self.assertEqual(evidence['shared'], evidence['records'][0]['rustup_home'])
        self.assertTrue(all(record['rustup_home'] == selected for record in evidence['records'][1:]))


if __name__ == '__main__':
    unittest.main()
