"""Protect automatic benchmark coverage and Benchmark Pages from run cancellation."""
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).parents[2]
PRODUCERS = (
    'archive-sample-performance',
    'yenc-performance',
    'native-utilities-performance',
    'release-presentation',
    'fingerprint-worker',
)


def concurrency_block(workflow_name):
    workflow = (ROOT / f'.github/workflows/{workflow_name}.yml').read_text()
    return workflow.split('\nconcurrency:\n', 1)[1].split('\njobs:', 1)[0]


def concurrency_group(producer, event, ref, run_id='1001'):
    block = concurrency_block(producer)
    group = re.search(r'^  group: (.+)$', block, re.M).group(1)
    group = (
        group.replace('${{ github.ref }}', ref)
        .replace('${{ github.event_name }}', event)
        .replace('${{ github.run_id }}', str(run_id))
    )
    if '${{' in group:
        raise AssertionError('Unrecognized concurrency expression')
    return group


class BenchmarkConcurrencyTest(unittest.TestCase):
    def test_manual_runs_cannot_replace_pending_automatic_runs(self):
        # GitHub keeps at most one pending run per concurrency group. Even with
        # cancel-in-progress:false a new dispatch cancels the old pending push
        # when they share a key. Distinct keys preserve both measurements.
        for producer in PRODUCERS:
            with self.subTest(producer=producer):
                push = concurrency_group(producer, 'push', 'refs/heads/master', '1001')
                manual = concurrency_group(producer, 'workflow_dispatch', 'refs/heads/master', '1002')
                self.assertNotEqual(push, manual)

    def test_new_workflow_runs_cannot_cancel_existing_runs(self):
        for workflow_name in (*PRODUCERS, 'benchmark-pages'):
            with self.subTest(workflow=workflow_name):
                block = concurrency_block(workflow_name)
                self.assertRegex(block, re.compile(r'^  cancel-in-progress:\s*false$', re.M))
                self.assertIn('${{ github.run_id }}', block)

    def test_same_trigger_and_run_id_is_deterministic_while_new_runs_stay_independent(self):
        keys = set()
        for producer in PRODUCERS:
            for event in ('push', 'pull_request', 'workflow_dispatch'):
                for ref in ('refs/heads/master', 'refs/heads/codex/test'):
                    for run_id in ('1001', '1002'):
                        key = concurrency_group(producer, event, ref, run_id)
                        self.assertEqual(key, concurrency_group(producer, event, ref, run_id))
                        self.assertNotIn(key, keys)
                        keys.add(key)


if __name__ == '__main__':
    unittest.main()
