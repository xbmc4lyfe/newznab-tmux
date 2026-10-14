import importlib.util
from pathlib import Path
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "benchmark-yenc-abi.py"
SPEC = importlib.util.spec_from_file_location("yenc_abi_benchmark", SCRIPT)
BENCHMARK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BENCHMARK)


class AbiOutputProbeTest(unittest.TestCase):
    def probe(self):
        return {
            "exact_output": True,
            "prefix_capacity_canary_intact": True,
            "suffix_capacity_canary_intact": True,
            "unused_capacity_changed_bytes": 0,
        }

    def test_rust_requires_exact_writes_while_cpp_reports_unused_capacity_stores(self):
        probe = self.probe()
        probe["unused_capacity_changed_bytes"] = 1
        self.assertFalse(BENCHMARK.valid_output(probe, "rust"))
        self.assertTrue(BENCHMARK.valid_output(probe, "cpp"))

    def test_both_backends_accept_exact_output_without_extra_stores(self):
        for backend in ("cpp", "rust"):
            self.assertTrue(BENCHMARK.valid_output(self.probe(), backend))

    def test_both_backends_reject_bad_output_or_outside_capacity_writes(self):
        for key in ("exact_output", "prefix_capacity_canary_intact", "suffix_capacity_canary_intact"):
            for backend in ("cpp", "rust"):
                probe = self.probe()
                probe[key] = False
                self.assertFalse(BENCHMARK.valid_output(probe, backend))


if __name__ == "__main__":
    unittest.main()
