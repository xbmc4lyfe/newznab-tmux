"""Verify comprehensive Rust module benchmark coverage and Component -> File -> Function -> Path drilldown."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
DRILLDOWN_SPEC = importlib.util.spec_from_file_location(
    'benchmark_pipeline_drilldown', ROOT / 'scripts/benchmark_pipeline_drilldown.py'
)
drilldown = importlib.util.module_from_spec(DRILLDOWN_SPEC)
DRILLDOWN_SPEC.loader.exec_module(drilldown)

PAGES_SPEC = importlib.util.spec_from_file_location('benchmark_pages', ROOT / 'scripts/benchmark-pages.py')
pages = importlib.util.module_from_spec(PAGES_SPEC)
PAGES_SPEC.loader.exec_module(pages)


class PipelineDrilldownTest(unittest.TestCase):
    def setUp(self):
        self.payload = drilldown.load_drilldown(ROOT)
        self.assertIsNotNone(self.payload, 'rust-module-benchmarks.json must exist and load')

    def test_every_rust_source_file_across_all_three_crates_is_benchmarked(self):
        expected_files = set(drilldown.discover_all_rust_files(ROOT))
        benchmarked_files = {
            file_entry['rust_file']
            for comp in self.payload['components']
            for file_entry in comp['files']
        }
        self.assertEqual(expected_files, benchmarked_files)
        self.assertGreaterEqual(len(benchmarked_files), 68)

    def test_every_pipeline_ffi_export_is_benchmarked_across_all_four_potential_paths(self):
        expected_exports = drilldown.discover_pipeline_exports(ROOT)
        self.assertGreaterEqual(len(expected_exports), 304)
        fn_map = {
            (file_entry['rust_file'], fn['name']): fn
            for comp in self.payload['components']
            for file_entry in comp['files']
            for fn in file_entry['functions']
        }
        pipeline_fns = [
            (rfile, fn)
            for (rfile, _), fn in fn_map.items()
            if rfile.startswith('rust/pipeline-ffi/src/')
        ]
        self.assertGreaterEqual(len(pipeline_fns), 304)
        for rfile, fn in pipeline_fns:
            with self.subTest(symbol=fn['name']):
                self.assertIn(fn['name'], expected_exports)
                self.assertEqual(expected_exports[fn['name']], rfile)
                self.assertEqual(
                    [p['path_id'] for p in fn['paths']],
                    ['hot_path', 'uncommon_tail', 'boundary_batch', 'fallback_edge'],
                )
                self.assertGreater(fn['hot_path_speedup'], 0)
                self.assertGreater(fn['uncommon_p99_speedup'], 0)
                self.assertGreater(fn['all_paths_speedup'], 0)

    def test_validation_rejects_missing_files_or_missing_potential_paths(self):
        broken = copy.deepcopy(self.payload)
        broken['components'][0]['files'][0]['functions'][0]['paths'].pop()
        with self.assertRaises(ValueError):
            drilldown.validate_drilldown(broken, ROOT)

    def test_rendered_benchmark_page_contains_component_file_function_and_path_drilldown(self):
        report = dict(
            head_sha='a' * 40,
            generated_at='2026-10-24T00:00:00Z',
            workflows=[],
            results=[],
            coverage=pages.coverage(ROOT),
            rust_exports=pages.rust_interfaces(ROOT),
            component_drilldown=self.payload,
        )
        html = pages.render(report)
        self.assertIn('id="component-speed-increase"', html)
        self.assertIn('id="component-file-function-drilldown"', html)
        for comp in self.payload['components']:
            self.assertIn(pages.escape(comp['name']), html)
            self.assertIn(f'id="component-{comp["id"]}"', html)
        self.assertIn('Hot Path (common)', html)
        self.assertIn('Uncommon Tail (p99)', html)
        self.assertIn('Boundary / Batch', html)
        self.assertIn('Fallback / Edge', html)
        self.assertIn('rust/pipeline-ffi/src/categorize.rs', html)
        self.assertIn('rust/pipeline-ffi/src/nfo.rs', html)
        self.assertIn('rust/fingerprint-worker/src/collect.rs', html)


if __name__ == '__main__':
    unittest.main()
