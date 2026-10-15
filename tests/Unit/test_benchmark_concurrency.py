"""Protect automatic benchmark coverage from manual producer dispatches."""
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).parents[2]
PRODUCERS = ('archive-sample-performance', 'yenc-performance', 'native-utilities-performance')


def concurrency_group(producer, event, ref):
    workflow = (ROOT / f'.github/workflows/{producer}.yml').read_text()
    block = workflow.split('\nconcurrency:\n', 1)[1].split('\njobs:', 1)[0]
    group = re.search(r'^  group: (.+)$', block, re.M).group(1)
    group = group.replace('${{ github.ref }}', ref).replace('${{ github.event_name }}', event)
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
                push = concurrency_group(producer, 'push', 'refs/heads/master')
                manual = concurrency_group(producer, 'workflow_dispatch', 'refs/heads/master')
                self.assertNotEqual(push, manual)

    def test_same_trigger_coalesces_but_branches_and_producers_are_independent(self):
        keys = set()
        for producer in PRODUCERS:
            for event in ('push', 'pull_request', 'workflow_dispatch'):
                for ref in ('refs/heads/master', 'refs/heads/codex/test'):
                    key = concurrency_group(producer, event, ref)
                    self.assertEqual(key, concurrency_group(producer, event, ref))
                    self.assertNotIn(key, keys)
                    keys.add(key)


if __name__ == '__main__':
    unittest.main()
