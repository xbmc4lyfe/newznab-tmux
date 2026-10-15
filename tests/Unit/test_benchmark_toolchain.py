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
    def run_helper(self, *, installed='', install_status=0, broken_after_install=''):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            rustup = root / 'rustup'
            rustup.write_text(f'#!{sys.executable}\n' + '''
import json
import os
from pathlib import Path
import sys

args = sys.argv[1:]
root = Path(__file__).parent
with (root / 'calls.jsonl').open('a') as log:
    log.write(json.dumps(args) + '\\n')
if args[:2] == ['toolchain', 'install']:
    if int(os.environ['INSTALL_STATUS']):
        print('read-only toolchain store', file=sys.stderr)
        raise SystemExit(int(os.environ['INSTALL_STATUS']))
    (root / 'installed').touch()
elif len(args) == 4 and args[0] == 'run' and args[3] == '-V':
    tool = args[2]
    available = (tool in os.environ['INSTALLED'].split(',') or
                 ((root / 'installed').exists() and tool != os.environ['BROKEN_AFTER_INSTALL']))
    if not available:
        raise SystemExit(1)
    print(tool + ' ' + args[1] + ' (fixture)')
else:
    raise SystemExit(99)
''')
            rustup.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'],
                       RUST_TOOLCHAIN=TOOLCHAIN, INSTALLED=installed,
                       INSTALL_STATUS=str(install_status), BROKEN_AFTER_INSTALL=broken_after_install)
            result = subprocess.run(['bash', str(SCRIPT)], env=env, text=True, capture_output=True)
            log = root / 'calls.jsonl'
            calls = [json.loads(line) for line in log.read_text().splitlines()] if log.exists() else []
            return result, calls

    def test_reuses_both_pinned_tools_when_install_would_fail_on_read_only_store(self):
        result, calls = self.run_helper(installed='rustc,cargo', install_status=30)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)

    def test_installs_missing_exact_toolchain_then_checks_both_tools(self):
        result, calls = self.run_helper()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['run', '1.98.0', 'rustc', '-V'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal'],
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)

    def test_missing_cargo_does_not_accept_a_rustc_only_installation(self):
        result, calls = self.run_helper(installed='rustc')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal'],
            ['run', '1.98.0', 'rustc', '-V'],
            ['run', '1.98.0', 'cargo', '-V'],
        ], calls)

    def test_install_failure_is_propagated_without_running_build_tools(self):
        result, calls = self.run_helper(install_status=30)
        self.assertEqual(30, result.returncode, result.stderr)
        self.assertEqual([
            ['run', '1.98.0', 'rustc', '-V'],
            ['toolchain', 'install', '1.98.0', '--profile', 'minimal'],
        ], calls)

    def test_successful_install_still_requires_working_rustc_and_cargo(self):
        for tool in ('rustc', 'cargo'):
            with self.subTest(tool=tool):
                result, calls = self.run_helper(broken_after_install=tool)
                self.assertNotEqual(0, result.returncode)
                self.assertTrue(calls)
                self.assertEqual(['run', '1.98.0', tool, '-V'], calls[-1])


if __name__ == '__main__':
    unittest.main()
