"""Publication-gate regressions; no native library or timing experiment needed."""
import argparse
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    'sevenzip_benchmark', Path(__file__).resolve().parents[2] / 'scripts/benchmark-sevenzip.py'
)
BENCH = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BENCH)


class SevenZipBenchmarkGateTest(unittest.TestCase):
    def options(self, **changes):
        values = dict(samples=31, iterations=20, seed=BENCH.SEED,
                      publication_gate=True, deadline_epoch=BENCH.PUBLICATION_DEADLINE)
        values.update(changes)
        return argparse.Namespace(**values)

    def worker(self):
        calls = [('cold', 0)] + [('warmup', i) for i in range(5)] + [('measured', i) for i in range(20)]
        return {'mode': 'native', 'fixture': 'compact', 'iterations': 20,
                'result': {'files': [{'name': 'movie.mkv'}], 'encrypted': False, 'success': True},
                'cold_ns': 1000, 'elapsed_ns': [100] * 20, 'consistent': True,
                'call_checks': [{'phase': phase, 'index': index, 'valid': True,
                                 'matches_first': True, 'signature': 'a' * 64, 'reason': None}
                                for phase, index in calls]}

    def test_bad_intermediate_call_cannot_hide_behind_matching_first_and_last(self):
        good = self.worker()
        self.assertIsNone(BENCH.validate_worker(good, 'native', 'compact', 20))
        for phase, index in [('warmup', 2), ('measured', 7)]:
            with self.subTest(phase=phase):
                bad = copy.deepcopy(good)
                call = next(c for c in bad['call_checks'] if (c['phase'], c['index']) == (phase, index))
                call['signature'] = 'b' * 64
                self.assertIn(f'{phase} call {index}', BENCH.validate_worker(bad, 'native', 'compact', 20))
        bad = copy.deepcopy(good)
        bad['call_checks'][13]['valid'] = False
        bad['call_checks'][13]['reason'] = 'Password/listing-only contract failed'
        self.assertIn('Password/listing-only', BENCH.validate_worker(bad, 'native', 'compact', 20))

    def test_deadline_prevents_another_worker_launch(self):
        with patch.object(BENCH.time, 'time', return_value=BENCH.PUBLICATION_DEADLINE), patch.object(BENCH.subprocess, 'run') as launch:
            result, error = BENCH.run_worker(['php', 'worker.php'], BENCH.PUBLICATION_DEADLINE)
        self.assertIsNone(result)
        self.assertIn('not launched', error['reason'])
        launch.assert_not_called()

    def test_publication_rejects_reduced_runs_and_an_extended_deadline(self):
        BENCH.validate_gate_options(self.options())
        for changes in [dict(samples=5), dict(iterations=1), dict(seed=99),
                        dict(deadline_epoch=None), dict(deadline_epoch=BENCH.PUBLICATION_DEADLINE + 1)]:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                BENCH.validate_gate_options(self.options(**changes))
        BENCH.validate_gate_options(self.options(publication_gate=False, samples=5,
                                                iterations=1, deadline_epoch=BENCH.PUBLICATION_DEADLINE + 86400))

    def test_baseline_or_fixture_hash_drift_is_retained_as_a_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            baseline = Path(directory) / 'baseline.php'
            fixture = Path(directory) / 'fixture.head'
            baseline.write_text('exact baseline')
            fixture.write_bytes(b'real fixture')
            frozen = BENCH.snapshot([baseline, fixture])
            self.assertEqual([], BENCH.changed_inputs(frozen))
            baseline.write_text('changed baseline')
            fixture.unlink()
            changes = BENCH.changed_inputs(frozen)
            self.assertEqual({str(baseline), str(fixture)}, {change['path'] for change in changes})

    def test_stratified_interval_keeps_the_frozen_fixture_mix(self):
        low, high = BENCH.bootstrap_stratified([[100] * 31, [1] * 31], seed=1, count=100)
        self.assertAlmostEqual(10, low)
        self.assertAlmostEqual(10, high)


if __name__ == '__main__':
    unittest.main()
