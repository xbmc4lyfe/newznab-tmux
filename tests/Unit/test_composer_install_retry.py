import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / 'scripts/composer-install-ci.sh'
INSTALL = ['composer', 'install', '--prefer-dist', '--no-interaction', '--no-progress', '--no-scripts']


class ComposerInstallRetryTest(unittest.TestCase):
    def run_install(self, responses, *, existing_log=False, tee_failure=False, sleep_failure=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binary = root / 'bin'
            binary.mkdir()
            (root / 'responses.json').write_text(json.dumps(responses))
            composer = binary / 'composer'
            composer.write_text(f'#!{sys.executable}\n' + '''
import json, os, pathlib, sys
root = pathlib.Path(os.environ['TEST_RUN_DIR'])
calls = root / 'calls.jsonl'
index = len(calls.read_text().splitlines()) if calls.exists() else 0
with calls.open('a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
responses = json.loads((root / 'responses.json').read_text())
code, message = responses[min(index, len(responses) - 1)]
print(message)
raise SystemExit(code)
''')
            composer.chmod(0o755)
            sleep = binary / 'sleep'
            sleep.write_text(f'#!{sys.executable}\n' + '''
import os, pathlib, sys
with (pathlib.Path(os.environ['TEST_RUN_DIR']) / 'sleeps').open('a') as log:
    log.write(sys.argv[1] + '\\n')
raise SystemExit(int(os.environ['TEST_SLEEP_STATUS']))
''')
            sleep.chmod(0o755)
            if tee_failure:
                tee = binary / 'tee'
                tee.write_text('#!/bin/sh\ncat >/dev/null\nexit 57\n')
                tee.chmod(0o755)
            evidence = root / 'evidence'
            if existing_log:
                evidence.mkdir()
                (evidence / 'attempt-1.log').write_text('retained evidence\n')
            env = dict(PATH=str(binary) + ':/usr/bin:/bin', TEST_RUN_DIR=str(root),
                       TEST_SLEEP_STATUS='130' if sleep_failure else '0')
            command = ['bash', str(SCRIPT), str(evidence)] if SCRIPT.exists() else INSTALL
            result = subprocess.run(command, env=env, text=True, capture_output=True, timeout=5)
            calls = root / 'calls.jsonl'
            sleeps = root / 'sleeps'
            return result, {
                'calls': [json.loads(line) for line in calls.read_text().splitlines()] if calls.exists() else [],
                'sleeps': sleeps.read_text().splitlines() if sleeps.exists() else [],
                'logs': {p.name: p.read_text() for p in evidence.glob('*.log')},
            }

    def test_rate_limit_recovers_with_same_locked_install_and_retained_attempts(self):
        result, evidence = self.run_install([(100, 'download failed (HTTP/2 429 )'), (0, 'installed')])
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([INSTALL[1:]] * 2, evidence['calls'])
        self.assertEqual(['60'], evidence['sleeps'])
        self.assertIn('HTTP/2 429', evidence['logs']['attempt-1.log'])
        self.assertIn('installed', evidence['logs']['attempt-2.log'])

    def test_persistent_rate_limit_preserves_failure_after_four_attempts(self):
        result, evidence = self.run_install([(100, 'download failed (HTTP/1.1 429 )')])
        self.assertEqual(100, result.returncode)
        self.assertEqual(4, len(evidence['calls']))
        self.assertEqual(['60', '120', '240'], evidence['sleeps'])
        self.assertEqual(4, len(evidence['logs']))

    def test_non_rate_limit_failure_is_not_retried(self):
        for message in ['HTTP/2 403', 'HTTP/2 500', '429 packages; platform requirements failed']:
            with self.subTest(message=message):
                result, evidence = self.run_install([(42, message)])
                self.assertEqual(42, result.returncode)
                self.assertEqual(1, len(evidence['calls']))
                self.assertEqual([], evidence['sleeps'])

    def test_success_does_not_sleep(self):
        result, evidence = self.run_install([(0, 'installed')])
        self.assertEqual(0, result.returncode)
        self.assertEqual(1, len(evidence['calls']))
        self.assertEqual([], evidence['sleeps'])

    def test_existing_evidence_is_not_overwritten(self):
        result, evidence = self.run_install([(0, 'installed')], existing_log=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual([], evidence['calls'])
        self.assertEqual('retained evidence\n', evidence['logs']['attempt-1.log'])

    def test_logging_failure_cannot_be_reported_as_install_success(self):
        result, evidence = self.run_install([(0, 'installed')], tee_failure=True)
        self.assertEqual(57, result.returncode)
        self.assertEqual(1, len(evidence['calls']))

    def test_cancelled_backoff_stops_before_another_install(self):
        result, evidence = self.run_install([(100, 'HTTP/2 429')], sleep_failure=True)
        self.assertEqual(130, result.returncode)
        self.assertEqual(1, len(evidence['calls']))


if __name__ == '__main__':
    unittest.main()
