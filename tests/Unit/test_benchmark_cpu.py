"""Portable fixtures for the benchmark CPU evidence collector."""

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from datetime import datetime, timezone

SCRIPT = Path(__file__).resolve().parents[2] / "scripts/benchmark-cpu.py"


class BenchmarkCpuTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(SCRIPT.is_file(), "CPU evidence collector is missing")
        spec = importlib.util.spec_from_file_location("benchmark_cpu", SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.proc = self.root / "proc"
        self.write(self.proc / "stat", "cpu  10 2 30 40 5 0 0 0 0 0\ncpu0 10 2 30 40\n")
        self.write(self.proc / "pressure/cpu", "some avg10=0.00 total=12\n")
        self.write(self.proc / "loadavg", "0.01 0.02 0.03 1/90 100\n")
        self.write(self.proc / "self/cgroup", "0::/team/job\n")
        self.mount = self.root / "cgroup"
        self.mountinfo("/")
        for path in (self.mount, self.mount / "team", self.mount / "team/job"):
            self.write(path / "cpu.stat", "usage_usec 100\nnr_throttled 3\nthrottled_usec 5\n")
            self.write(path / "cpu.max", "100000 100000\n")
            self.write(path / "cpu.pressure", "some avg10=0.00 total=10\n")

    def write(self, path, value):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value)

    def mountinfo(self, root):
        escaped = str(self.mount).replace(" ", "\\040")
        self.write(self.proc / "self/mountinfo", f"29 23 0:26 {root} {escaped} rw - cgroup2 cgroup rw\n")

    def snapshot(self):
        return self.module.snapshot(self.proc)

    def test_retains_raw_os_and_leaf_through_root_cgroup_counters(self):
        data = self.snapshot()
        self.assertTrue(data["os_cpu_usable"])
        self.assertEqual(data["os"]["stat"]["raw"], (self.proc / "stat").read_text())
        self.assertEqual(data["os"]["pressure_cpu"]["status"], "captured")
        cg = data["cgroup_v2"]
        self.assertEqual(cg["status"], "captured")
        self.assertEqual([row["path"] for row in cg["ancestors"]],
                         [str(self.mount / "team/job"), str(self.mount / "team"), str(self.mount)])
        self.assertEqual(cg["ancestors"][1]["cpu.max"]["raw"], "100000 100000\n")
        self.assertEqual(cg["ancestors"][0]["cpu.stat"]["raw"].splitlines()[1], "nr_throttled 3")

    def test_nonroot_mount_resolves_membership_and_marks_hidden_ancestors(self):
        self.mountinfo("/team")
        cg = self.snapshot()["cgroup_v2"]
        self.assertEqual(cg["ancestors"][0]["path"], str(self.mount / "job"))
        self.assertEqual(len(cg["ancestors"]), 2)
        self.assertEqual(cg["above_mount_root"], "unavailable")

    def test_namespace_root_and_mountpoint_escapes(self):
        self.mount = self.root / "cgroup space"
        self.mountinfo("/")
        self.write(self.proc / "self/cgroup", "0::/\n")
        cg = self.snapshot()["cgroup_v2"]
        self.assertEqual(cg["ancestors"][0]["path"], str(self.mount))
        self.assertEqual(len(cg["ancestors"]), 1)

    def test_incompatible_mount_root_is_not_guessed(self):
        self.mountinfo("/other")
        cg = self.snapshot()["cgroup_v2"]
        self.assertEqual(cg["status"], "unavailable")
        self.assertIn("mount", cg["error"])

    def test_v1_and_absent_membership_remain_unavailable(self):
        for membership in ("2:cpu:/team/job\n", "garbage\n"):
            with self.subTest(membership=membership):
                self.write(self.proc / "self/cgroup", membership)
                cg = self.snapshot()["cgroup_v2"]
                self.assertEqual(cg["status"], "unavailable")
                self.assertEqual(cg["membership"]["raw"], membership)
        (self.proc / "self/cgroup").unlink()
        self.assertEqual(self.snapshot()["cgroup_v2"]["membership"]["status"], "unavailable")

    def test_optional_local_throttling_and_root_quota_absence_are_preserved(self):
        self.write(self.mount / "team/job/cpu.stat.local", "throttled_usec 17\n")
        (self.mount / "cpu.max").unlink()
        cg = self.snapshot()["cgroup_v2"]
        self.assertEqual(cg["ancestors"][0].get("cpu.stat.local", {}).get("raw"), "throttled_usec 17\n")
        self.assertEqual(cg["ancestors"][-1]["cpu.max"]["status"], "unavailable")
        self.assertEqual(cg["ancestors"][-1]["cpu.stat.local"]["status"], "unavailable")

    def test_missing_optional_files_retain_errors(self):
        (self.proc / "pressure/cpu").unlink()
        (self.mount / "team/cpu.max").unlink()
        data = self.snapshot()
        self.assertTrue(data["os_cpu_usable"])
        self.assertEqual(data["os"]["pressure_cpu"]["status"], "unavailable")
        self.assertIn("error", data["cgroup_v2"]["ancestors"][1]["cpu.max"])

    def test_malformed_mountinfo_retained_without_false_cgroup_capture(self):
        self.write(self.proc / "self/mountinfo", "malformed - cgroup2\n")
        cg = self.snapshot()["cgroup_v2"]
        self.assertEqual(cg["status"], "unavailable")
        self.assertEqual(cg["mountinfo"]["raw"], "malformed - cgroup2\n")

    def test_malformed_or_absent_cpu_ticks_fail_qualification_with_artifact(self):
        output = self.root / "snapshot.json"
        for raw in ("cpu no ticks here\n", "cpu 1 2 3\n", "cpu -1 2 3 4\n", "intr 100\n", None):
            with self.subTest(raw=raw):
                if raw is None:
                    (self.proc / "stat").unlink()
                else:
                    self.write(self.proc / "stat", raw)
                self.assertEqual(self.module.main(["--output", str(output)], self.proc), 1)
                self.assertFalse(json.loads(output.read_text())["os_cpu_usable"])
                output.unlink()

    def test_output_is_exclusive_and_timestamped(self):
        output = self.root / "snapshot.json"
        before = datetime.now(timezone.utc)
        self.assertEqual(self.module.main(["--output", str(output)], self.proc), 0)
        data = json.loads(output.read_text())
        self.assertGreaterEqual(datetime.fromisoformat(data["captured_at"]), before)
        self.assertGreater(data["monotonic_ns"], 0)
        self.assertEqual(data["schema_version"], 1)
        original = output.read_bytes()
        self.assertEqual(self.module.main(["--output", str(output)], self.proc), 2)
        self.assertEqual(output.read_bytes(), original)


if __name__ == "__main__":
    unittest.main()
