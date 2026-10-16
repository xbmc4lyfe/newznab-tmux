"""Synthetic receipt-state tests; these never compile native code."""

from __future__ import annotations

import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/benchmark-date-build.py"


class DateBuildReceiptTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(SCRIPT.is_file(), "The executed-build receipt helper must exist.")
        spec = importlib.util.spec_from_file_location("date_build_receipt", SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        self.temporary = tempfile.TemporaryDirectory(prefix="nntmux-build-receipt-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / "scripts/benchmark-date-cpp.cpp"
        self.source.parent.mkdir()
        self.source.write_text("// synthetic source; no compilation\n")
        self.compiler = self.root / "fixture-compiler"
        self.compiler.write_text("synthetic compiler identity\n")
        self.compiler.chmod(0o700)
        self.sources = {"scripts/benchmark-date-cpp.cpp": hashlib.sha256(self.source.read_bytes()).hexdigest()}
        self.evidence = self.root / "evidence"

    def run_fixture(self, behavior: str) -> dict:
        def execute(command: list[str], stdout: Path, stderr: Path) -> int:
            stdout.write_text("synthetic compiler version\n" if "--version" in command else "synthetic build log\n")
            stderr.write_text("")
            if "--version" in command:
                if behavior == "version_nonzero":
                    return 17
                if behavior == "version_empty":
                    stdout.write_text("")
                return 0
            output = Path(command[command.index("-o") + 1])
            if behavior == "nonzero":
                return 7
            if behavior in ("timeout", "killed"):
                return 124 if behavior == "timeout" else 137
            if behavior == "missing":
                return 0
            if behavior == "source_changed":
                self.source.write_text("changed synthetic source\n")
            if behavior == "compiler_changed":
                self.compiler.write_text("changed synthetic compiler\n")
            if behavior == "empty":
                output.write_bytes(b"")
            elif behavior == "nonelf":
                output.write_bytes(b"not an ELF file")
            elif behavior == "symlink":
                output.symlink_to(self.source)
            else:
                output.write_bytes(b"\x7fELFsynthetic fixture, not a native library")
            return 0

        return self.module.build_reference(self.root, self.evidence, self.sources,
                                           compiler=self.compiler, timeout_tool=self.compiler, execute=execute)

    def test_a_fixture_build_retains_evidence_but_cannot_emit_a_production_manifest(self) -> None:
        receipt = self.run_fixture("success")
        self.assertEqual(receipt["build_status"], "test_fixture_completed")
        self.assertTrue(receipt["test_fixture"])
        self.assertEqual(receipt["compiler_command"][0], str(self.compiler.resolve()))
        self.assertEqual(receipt["compiler_command"][1:9], ["-std=c++20", "-O3", "-DNDEBUG", "-fPIC", "-shared", "-Wall", "-Wextra", "-Werror"])
        self.assertEqual(receipt["baseline_source_sha256"], self.sources)
        self.assertEqual(receipt["library_sha256"], hashlib.sha256((self.evidence / "libdate-reference.so").read_bytes()).hexdigest())
        self.assertTrue((self.evidence / "build-receipt.json").is_file())
        self.assertFalse((self.evidence / "baseline-manifest.json").exists())

    def test_nonzero_exit_is_retained_as_a_failed_build(self) -> None:
        receipt = self.run_fixture("nonzero")
        self.assertEqual(receipt["build_status"], "compiler_failed")
        self.assertEqual(receipt["compiler_exit"], 7)
        self.assertFalse((self.evidence / "baseline-manifest.json").exists())

    def test_success_without_output_cannot_become_build_evidence(self) -> None:
        self.assertEqual(self.run_fixture("missing")["build_status"], "output_missing")

    def test_source_drift_invalidates_a_successful_exit(self) -> None:
        self.assertEqual(self.run_fixture("source_changed")["build_status"], "source_changed")

    def test_compiler_drift_invalidates_a_successful_exit(self) -> None:
        self.assertEqual(self.run_fixture("compiler_changed")["build_status"], "tool_changed")

    def test_a_symlink_output_is_not_hashed_as_a_library(self) -> None:
        receipt = self.run_fixture("symlink")
        self.assertEqual(receipt["build_status"], "output_not_regular")
        self.assertNotIn("library_sha256", receipt)

    def test_version_failure_or_empty_output_stops_before_compilation(self) -> None:
        for behavior in ["version_nonzero", "version_empty"]:
            with self.subTest(behavior=behavior):
                self.evidence = self.root / behavior
                receipt = self.run_fixture(behavior)
                self.assertNotIn(receipt["build_status"], ["completed", "test_fixture_completed"])
                self.assertNotIn("compiler_exit", receipt)
                self.assertEqual(len(receipt["commands"]), 1)
                self.assertFalse((self.evidence / "baseline-manifest.json").exists())

    def test_timeout_and_kill_statuses_do_not_prove_cleanup_or_build_success(self) -> None:
        for behavior, expected in [("timeout", 124), ("killed", 137)]:
            with self.subTest(behavior=behavior):
                self.evidence = self.root / behavior
                receipt = self.run_fixture(behavior)
                self.assertEqual(receipt["build_status"], "compiler_failed")
                self.assertEqual(receipt["compiler_exit"], expected)
                self.assertFalse(receipt["descendant_absence_verified"])
                self.assertEqual(set(receipt["commands"][-1]["logs"]), {"compile.stdout.log", "compile.stderr.log"})
                self.assertFalse((self.evidence / "baseline-manifest.json").exists())

    def test_empty_or_nonelf_outputs_never_get_a_library_hash(self) -> None:
        for behavior in ["empty", "nonelf"]:
            with self.subTest(behavior=behavior):
                self.evidence = self.root / behavior
                receipt = self.run_fixture(behavior)
                self.assertEqual(receipt["build_status"], "output_invalid")
                self.assertNotIn("library_sha256", receipt)
                self.assertFalse((self.evidence / "baseline-manifest.json").exists())

    def test_an_existing_evidence_directory_is_preserved(self) -> None:
        self.evidence.mkdir()
        marker = self.evidence / "owned-by-other-run"
        marker.write_text("preserve")
        with self.assertRaises(FileExistsError):
            self.run_fixture("success")
        self.assertEqual(marker.read_text(), "preserve")


if __name__ == "__main__":
    unittest.main()
