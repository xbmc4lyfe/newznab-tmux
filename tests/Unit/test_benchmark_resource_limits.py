#!/usr/bin/env python3
"""Correctness checks for benchmark resource evidence, without timed workloads."""

import copy
import importlib.util
import json
import pathlib
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("resources", ROOT / "scripts/benchmark-resource-limits.py")
RESOURCES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RESOURCES)


class ResourceLimitsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name)
        self.proc = self.root / "proc"
        (self.proc / "self").mkdir(parents=True)
        for name, value in {"cgroup": "0::/runner/job\n", "limits": "Limit Soft Hard\n",
                            "mountinfo": "visible cgroup2 mount\n"}.items():
            (self.proc / "self" / name).write_text(value)
        self.cgroup = self.root / "cgroup"
        leaf = self.cgroup / "runner/job"
        leaf.mkdir(parents=True)
        (self.cgroup / "cgroup.controllers").write_text("cpu memory pids cpuset")
        for directory in (leaf, leaf.parent, self.cgroup):
            for name in RESOURCES.STATIC_FIELDS:
                (directory / name).write_text("max")
            for name in RESOURCES.DYNAMIC_FIELDS:
                (directory / name).write_text("0")
        self.soft = self.root / "soft.txt"
        self.hard = self.root / "hard.txt"
        self.soft.write_text("open files 1024\n")
        self.hard.write_text("open files 4096\n")

    def snapshot(self):
        with patch.object(RESOURCES.os, "sched_getaffinity", return_value={0, 2}, create=True):
            return RESOURCES.capture(self.soft, self.hard, self.proc, self.cgroup)

    def compare(self, before, after):
        left, right = self.root / "before.json", self.root / "after.json"
        left.write_text(json.dumps(before))
        right.write_text(json.dumps(after))
        return RESOURCES.compare(left, right)

    def test_capture_retains_all_ancestors_raw_limits_and_usage(self):
        snapshot = self.snapshot()
        self.assertEqual([entry["cgroup"] for entry in snapshot["cgroup_v2_ancestors"]],
                         ["/runner/job", "/runner", "/"])
        self.assertEqual(snapshot["cpu_affinity"], [0, 2])
        self.assertEqual(snapshot["shell_soft_limits"], self.soft.read_text())
        self.assertEqual(snapshot["outer_host_limits"], "unknown")
        self.assertIn("unknown", snapshot["scope"])
        self.assertEqual(snapshot["cgroup_v2_ancestors"][0]["usage"]["cpu.stat"], "0")

    def test_dynamic_usage_does_not_invalidate_stable_static_limits(self):
        before = self.snapshot()
        after = copy.deepcopy(before)
        after["captured_at_unix_ns"] += 1
        after["cgroup_v2_ancestors"][1]["usage"]["cpu.stat"] = "usage_usec 12345"
        result = self.compare(before, after)
        self.assertTrue(result["valid"])
        self.assertTrue(result["stable_static_limits"])
        self.assertFalse(result["dynamic_usage_compared_for_stability"])

    def test_present_io_limits_are_frozen_at_every_ancestor_and_receipt_rejects_drift(self):
        left, right, receipt = (self.root / f'resource-limits-{name}.json'
                                for name in ('before', 'after', 'comparison'))
        cases = {
            'io.max': ('8:0 rbps=max wbps=1048576 riops=max wiops=max',
                       '8:0 rbps=max wbps=10485760 riops=max wiops=max'),
            'io.weight': ('default 100', 'default 1000'),
        }
        for directory in (self.cgroup / 'runner/job', self.cgroup / 'runner', self.cgroup):
            for field, (initial, changed) in cases.items():
                with self.subTest(cgroup=directory, field=field):
                    (directory / field).write_text(initial + '\n')
                    before = self.snapshot()
                    left.write_text(json.dumps(before))
                    right.write_text(json.dumps(before))
                    receipt.write_text(json.dumps(RESOURCES.compare(left, right)))
                    RESOURCES.verified_receipt(self.root)
                    (directory / field).write_text(changed + '\n')
                    after = self.snapshot()
                    result = self.compare(before, after)
                    self.assertTrue(result['valid'])
                    self.assertFalse(result['stable_static_limits'])
                    self.assertIn('cgroup_v2_ancestors', result['changed_fields'])
                    # Keep the earlier claimed stable receipt: exporters must
                    # independently notice the raw I/O endpoint changed.
                    right.write_text(json.dumps(after))
                    with self.assertRaises(ValueError):
                        RESOURCES.verified_receipt(self.root)

    def test_absent_io_controller_is_unknown_and_presence_transitions_fail_stability(self):
        directories = (self.cgroup / 'runner/job', self.cgroup / 'runner', self.cgroup)
        for directory in directories:
            for field in ('io.max', 'io.weight'):
                (directory / field).unlink(missing_ok=True)
            (directory / 'cgroup.controllers').write_text('cpu memory pids cpuset\n')
            (directory / 'cgroup.subtree_control').write_text('cpu memory pids cpuset\n')
        absent = self.snapshot()
        self.assertEqual(absent['missing_controller_files'], 'unknown; never interpreted as unlimited')
        for ancestor in absent['cgroup_v2_ancestors']:
            self.assertIsNone(ancestor['limits']['io.max'])
            self.assertIsNone(ancestor['limits']['io.weight'])
            self.assertEqual(ancestor['limits']['cgroup.controllers'], 'cpu memory pids cpuset')
        self.assertTrue(self.compare(absent, self.snapshot())['stable_static_limits'])
        for field in ('io.max', 'io.weight'):
            with self.subTest(field=field):
                limit = directories[0] / field
                limit.write_text('default 100' if field == 'io.weight' else '8:0 wbps=1048576')
                present = self.snapshot()
                self.assertFalse(self.compare(absent, present)['stable_static_limits'])
                limit.unlink()
                removed = self.snapshot()
                self.assertFalse(self.compare(present, removed)['stable_static_limits'])
                self.assertTrue(self.compare(absent, removed)['stable_static_limits'])

    def test_io_field_removal_from_raw_endpoint_is_rejected(self):
        before = self.snapshot()
        for field in ('io.max', 'io.weight'):
            with self.subTest(field=field):
                after = copy.deepcopy(before)
                del after['cgroup_v2_ancestors'][0]['limits'][field]
                result = self.compare(before, after)
                self.assertFalse(result['valid'])
                self.assertFalse(result['stable_static_limits'])

    def test_io_controller_availability_changes_are_static(self):
        directory = self.cgroup / 'runner/job'
        for field in ('cgroup.controllers', 'cgroup.subtree_control'):
            with self.subTest(field=field):
                (directory / field).write_text('cpu memory\n')
                before = self.snapshot()
                (directory / field).write_text('cpu memory io\n')
                self.assertFalse(self.compare(before, self.snapshot())['stable_static_limits'])

    def test_static_limit_affinity_membership_or_shell_change_fails_stability(self):
        before = self.snapshot()
        changes = [
            lambda snapshot: snapshot["cgroup_v2_ancestors"][1]["limits"].update({"cpu.max": "100000 100000"}),
            lambda snapshot: snapshot.update(cpu_affinity=[0]),
            lambda snapshot: snapshot.update(shell_hard_limits="open files 8192\n"),
            lambda snapshot: snapshot.update(cgroup_membership="0::/other\n"),
        ]
        for change in changes:
            after = copy.deepcopy(before)
            change(after)
            result = self.compare(before, after)
            self.assertTrue(result["valid"])
            self.assertFalse(result["stable_static_limits"])
            self.assertEqual(result["outcome"], "changed-visible-limits")

    def test_missing_invalid_or_incomplete_endpoints_fail_closed(self):
        result = RESOURCES.compare(self.root / "missing", self.root / "also-missing")
        self.assertFalse(result["valid"])
        before = self.snapshot()
        invalid = [None, {}, {"valid": False}]
        missing_timestamp = copy.deepcopy(before)
        del missing_timestamp["captured_at_unix_ns"]
        invalid.append(missing_timestamp)
        missing_ancestor = copy.deepcopy(before)
        del missing_ancestor["cgroup_v2_ancestors"][1]
        invalid.append(missing_ancestor)
        missing_limit = copy.deepcopy(before)
        del missing_limit["cgroup_v2_ancestors"][0]["limits"]["cpu.max"]
        invalid.append(missing_limit)
        for after in invalid:
            with self.subTest(after=after):
                result = self.compare(before, after)
                self.assertFalse(result["valid"])
                self.assertFalse(result["stable_static_limits"])

    def test_unobserved_limits_and_escape_do_not_become_unlimited(self):
        for directory in (self.cgroup, self.cgroup / "runner", self.cgroup / "runner/job"):
            (directory / "memory.max").unlink()
        with self.assertRaisesRegex(ValueError, "Unobserved"):
            self.snapshot()
        (self.proc / "self/cgroup").write_text("0::/../outside\n")
        with self.assertRaises(ValueError):
            self.snapshot()

    def test_verified_receipt_hashes_fixed_endpoints_and_rejects_forged_stability(self):
        before = self.snapshot()
        after = copy.deepcopy(before)
        after['captured_at_unix_ns'] += 1
        left, right, receipt = (self.root / f'resource-limits-{name}.json' for name in ('before', 'after', 'comparison'))
        left.write_text(json.dumps(before))
        right.write_text(json.dumps(after))
        receipt.write_text(json.dumps(RESOURCES.compare(left, right)))
        hashes = RESOURCES.verified_receipt(self.root)
        self.assertEqual(set(hashes), {'resource_limits_before', 'resource_limits_after', 'resource_limits_comparison'})
        self.assertTrue(all(len(value) == 64 for value in hashes.values()))
        after['cpu_affinity'] = [7]
        right.write_text(json.dumps(after))
        with self.assertRaises(ValueError):
            RESOURCES.verified_receipt(self.root)
        right.write_text(json.dumps(before))
        document = json.loads(receipt.read_text())
        document['before'] = str(self.root / 'unrelated')
        receipt.write_text(json.dumps(document))
        with self.assertRaises(ValueError):
            RESOURCES.verified_receipt(self.root)
        receipt.unlink()
        with self.assertRaises(FileNotFoundError):
            RESOURCES.verified_receipt(self.root)

    def test_cli_records_failed_capture_and_missing_comparison(self):
        output = self.root / "evidence.json"
        with patch("sys.argv", ["resources", "capture", "--soft-limits", str(self.soft),
                               "--hard-limits", str(self.hard), "--output", str(output)]), \
                patch.object(RESOURCES, "capture", side_effect=OSError("unreadable controller")):
            self.assertEqual(RESOURCES.main(), 1)
        self.assertFalse(json.loads(output.read_text())["valid"])
        with patch("sys.argv", ["resources", "compare", "--before", str(self.root / "missing"),
                               "--after", str(self.root / "missing2"), "--output", str(output)]):
            self.assertEqual(RESOURCES.main(), 1)
        self.assertEqual(json.loads(output.read_text())["outcome"], "invalid-resource-endpoints")


if __name__ == "__main__":
    unittest.main()
