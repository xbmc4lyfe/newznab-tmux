import importlib.util
from pathlib import Path
import tempfile
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


if __name__ == '__main__':
    unittest.main()
