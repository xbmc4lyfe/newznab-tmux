import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).parents[2]
WORKFLOW = ROOT / '.github/workflows/rust-clippy.yml'
TOOLS = ('rustc', 'cargo', 'cargo-clippy', 'clippy-driver', 'rustfmt', 'cargo-fmt')


def setup_command():
    lines = WORKFLOW.read_text().splitlines()
    start = lines.index('      - name: Install Rust toolchain') + 1
    while not lines[start].lstrip().startswith('run:'):
        start += 1
    value = lines[start].strip()[4:].strip()
    if value != '|':
        return value
    commands = []
    for line in lines[start + 1:]:
        if line.strip() and not line.startswith('          '):
            break
        commands.append(line[10:])
    return '\n'.join(commands)


class ClippyToolchainTest(unittest.TestCase):
    def run_setup(self, *, installed=TOOLS, install_status=0, broken_before_install='',
                  broken_after_install='', absent_after_install='', missing_env='',
                  mktemp_failure=False):
        with tempfile.TemporaryDirectory(prefix='clippy fixture ') as directory:
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
    log.write(json.dumps({'args': args, 'home': str(home), 'cargo': os.environ['CARGO_HOME']}) + '\\n')
if args[:2] == ['toolchain', 'install']:
    if home == root / 'shared-rustup':
        raise SystemExit(30)
    if int(os.environ['INSTALL_STATUS']):
        raise SystemExit(int(os.environ['INSTALL_STATUS']))
    (home / 'installed').touch()
elif args[:3] == ['which', '--toolchain', '1.98.0'] and len(args) == 4:
    tool = args[3]
    available = ((home == root / 'shared-rustup' and tool in os.environ['INSTALLED'].split(',')) or
                 ((home / 'installed').exists() and tool != os.environ['ABSENT_AFTER_INSTALL']))
    if not available:
        raise SystemExit(1)
    path = home / ('fixture-' + tool)
    installed = home != root / 'shared-rustup'
    path.write_text('#!' + sys.executable + '\\nimport json,os,sys\\n' +
                    'with open(' + repr(str(root / 'tools.jsonl')) + ', "a") as log:\\n' +
                    '    log.write(json.dumps({"tool": ' + repr(tool) +
                    ', "args": sys.argv[1:], "cargo": os.environ["CARGO_HOME"]}) + "\\\\n")\\n' +
                    'raise SystemExit(12 if os.environ.get(' +
                    repr('BROKEN_AFTER_INSTALL' if installed else 'BROKEN_BEFORE_INSTALL') +
                    ') == ' + repr(tool) + ' else 0)\\n')
    path.chmod(0o755)
    print(path)
elif args and args[0] == 'run':
    # Rustup can fall through PATH for missing tools. This must not qualify reuse.
    raise SystemExit(0)
else:
    raise SystemExit(99)
''')
            rustup.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'],
                       RUST_TOOLCHAIN='1.98.0', INSTALLED=','.join(installed),
                       INSTALL_STATUS=str(install_status), BROKEN_AFTER_INSTALL=broken_after_install,
                       BROKEN_BEFORE_INSTALL=broken_before_install, ABSENT_AFTER_INSTALL=absent_after_install,
                       RUSTUP_HOME=str(shared), RUNNER_TEMP=str(runner_temp),
                       GITHUB_ENV=str(root / 'github-env'), CARGO_HOME=str(root / 'existing-cargo'))
            if missing_env:
                env.pop(missing_env)
            if mktemp_failure:
                mock_mktemp = root / 'mktemp'
                mock_mktemp.write_text('#!/bin/sh\nexit 43\n')
                mock_mktemp.chmod(0o755)
            command = setup_command() + '\nprintf "%s\\n" "$RUSTUP_HOME" "$CARGO_HOME" > "$1"'
            state = root / 'caller-state'
            result = subprocess.run(['bash', '-e', '-o', 'pipefail', '-c', command, 'setup', str(state)],
                                    cwd=ROOT, env=env, text=True, capture_output=True)
            records_path = root / 'calls.jsonl'
            self.assertTrue(records_path.exists(), result.stdout + result.stderr)
            records = [json.loads(line) for line in records_path.read_text().splitlines()]
            self.assertTrue(all(record['cargo'] == env['CARGO_HOME'] for record in records))
            tools_path = root / 'tools.jsonl'
            tools = [json.loads(line) for line in tools_path.read_text().splitlines()] if tools_path.exists() else []
            self.assertTrue(all(tool['cargo'] == env['CARGO_HOME'] for tool in tools))
            github_env = root / 'github-env'
            return result, records, {
                'shared': str(shared), 'runner_temp': str(runner_temp),
                'cargo': env['CARGO_HOME'],
                'state': state.read_text().splitlines() if state.exists() else [],
                'later_env': github_env.read_text() if github_env.exists() else '',
                'tools': tools,
            }

    def test_complete_pin_is_reused_without_attempting_read_only_install(self):
        result, records, evidence = self.run_setup(install_status=30)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([['which', '--toolchain', '1.98.0', tool] for tool in TOOLS],
                         [record['args'] for record in records])
        self.assertEqual([evidence['shared'], evidence['cargo']], evidence['state'])
        self.assertEqual('', evidence['later_env'])
        self.assertEqual(list(TOOLS), [tool['tool'] for tool in evidence['tools']])
        self.assertTrue(all(tool['args'] == ['--version'] for tool in evidence['tools']))

    def test_each_missing_component_uses_a_fresh_job_store_not_path_fallback(self):
        for missing in TOOLS:
            with self.subTest(missing=missing):
                result, records, evidence = self.run_setup(installed=tuple(t for t in TOOLS if t != missing))
                self.assertEqual(0, result.returncode, result.stderr)
                installs = [r for r in records if r['args'][:2] == ['toolchain', 'install']]
                self.assertEqual(1, len(installs))
                self.assertEqual(['toolchain', 'install', '1.98.0', '--profile', 'minimal',
                                  '--component', 'clippy', '--component', 'rustfmt', '--no-self-update'],
                                 installs[0]['args'])
                selected = evidence['state'][0]
                self.assertEqual(evidence['runner_temp'], str(Path(selected).parent))
                self.assertNotEqual(evidence['shared'], selected)
                self.assertEqual(selected, installs[0]['home'])
                self.assertEqual(evidence['cargo'], evidence['state'][1])
                self.assertEqual('RUSTUP_HOME=' + selected + '\n', evidence['later_env'])
                self.assertEqual([['which', '--toolchain', '1.98.0', tool] for tool in TOOLS],
                                 [r['args'] for r in records[-len(TOOLS):]])
                self.assertEqual(list(TOOLS), [tool['tool'] for tool in evidence['tools'][-len(TOOLS):]])

    def test_broken_preinstalled_component_uses_job_fallback(self):
        result, records, evidence = self.run_setup(broken_before_install='rustfmt')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue(any(r['args'][:2] == ['toolchain', 'install'] for r in records))
        self.assertNotEqual(evidence['shared'], evidence['state'][0])

    def test_successful_install_missing_a_component_does_not_publish_environment(self):
        result, _, evidence = self.run_setup(installed=(), absent_after_install='clippy-driver')
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('', evidence['later_env'])
        self.assertEqual([], evidence['state'])

    def test_missing_fallback_environment_fails_before_install(self):
        for missing in ('RUNNER_TEMP', 'GITHUB_ENV'):
            with self.subTest(missing=missing):
                result, records, evidence = self.run_setup(installed=(), missing_env=missing)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(any(r['args'][:2] == ['toolchain', 'install'] for r in records))
                self.assertEqual('', evidence['later_env'])
                self.assertEqual([], evidence['state'])

    def test_unavailable_job_store_fails_before_install(self):
        result, records, evidence = self.run_setup(installed=(), mktemp_failure=True)
        self.assertEqual(43, result.returncode, result.stderr)
        self.assertFalse(any(r['args'][:2] == ['toolchain', 'install'] for r in records))
        self.assertEqual('', evidence['later_env'])
        self.assertEqual([], evidence['state'])

    def test_failed_download_propagates_without_publishing_environment(self):
        result, records, evidence = self.run_setup(installed=(), install_status=42)
        self.assertEqual(42, result.returncode, result.stderr)
        self.assertEqual('', evidence['later_env'])
        self.assertEqual([], evidence['state'])
        self.assertEqual('toolchain', records[-1]['args'][0])

    def test_successful_install_requires_all_component_executables_to_work(self):
        for broken in TOOLS:
            with self.subTest(broken=broken):
                result, _, evidence = self.run_setup(installed=(), broken_after_install=broken)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual('', evidence['later_env'])
                self.assertEqual([], evidence['state'])


if __name__ == '__main__':
    unittest.main()
