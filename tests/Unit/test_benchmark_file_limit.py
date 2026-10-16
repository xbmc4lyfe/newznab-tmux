"""Real inherited regular-file limits for the diagnostic benchmark launcher."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
import resource
import subprocess
import sys
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/benchmark-file-limit.py"


class BenchmarkFileLimitTest(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(SCRIPT.is_file(), "The bounded launcher must exist.")

    def launch(self, limit: int, command: list[str], **kwargs: object) -> subprocess.CompletedProcess[str]:
        if "stdout" not in kwargs and "stderr" not in kwargs:
            kwargs["capture_output"] = True
        return subprocess.run(
            [sys.executable, "-B", str(SCRIPT), "--file-limit-bytes", str(limit), "--", *command],
            text=True, timeout=10, **kwargs,
        )

    def test_exact_limit_succeeds_and_is_inherited_by_the_executed_process(self) -> None:
        with tempfile.TemporaryDirectory(prefix="nntmux-file-limit-") as directory:
            target = Path(directory) / "payload"
            code = "import pathlib,resource,json,sys; pathlib.Path(sys.argv[1]).write_bytes(b'x'*4096); print(json.dumps(list(resource.getrlimit(resource.RLIMIT_FSIZE))))"
            result = self.launch(4096, [sys.executable, "-c", code, str(target)])
            self.assertEqual(result.returncode, 0, result.stderr)
            receipt, inherited = map(json.loads, result.stdout.splitlines())
            self.assertEqual(receipt["effective_file_limit_bytes"], 4096)
            self.assertEqual(receipt["limit_scope"], "per_regular_file")
            self.assertFalse(receipt["aggregate_limit_enforced"])
            self.assertEqual(receipt["command"][0], str(Path(sys.executable).resolve()))
            self.assertEqual(receipt["requested_command"][0], sys.executable)
            self.assertEqual(receipt["launcher_file_sha256"], hashlib.sha256(SCRIPT.read_bytes()).hexdigest())
            self.assertEqual(inherited, [4096, 4096])
            self.assertEqual(target.read_bytes(), b"x" * 4096)

    def test_one_byte_over_the_limit_fails_without_writing_beyond_it(self) -> None:
        with tempfile.TemporaryDirectory(prefix="nntmux-file-limit-") as directory:
            target = Path(directory) / "payload"
            code = "import os,sys; f=os.open(sys.argv[1],os.O_WRONLY|os.O_CREAT,0o600); os.write(f,b'x'*4096); os.write(f,b'y'); os.close(f)"
            result = self.launch(4096, [sys.executable, "-c", code, str(target)])
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(target.read_bytes(), b"x" * 4096)
            self.assertEqual(json.loads(result.stdout.splitlines()[0])["effective_file_limit_bytes"], 4096)

    def test_an_existing_stricter_limit_is_preserved(self) -> None:
        def restrict() -> None:
            resource.setrlimit(resource.RLIMIT_FSIZE, (2048, 2048))

        result = self.launch(4096, [sys.executable, "-c", "import resource,json; print(json.dumps(list(resource.getrlimit(resource.RLIMIT_FSIZE))))"], preexec_fn=restrict)
        self.assertEqual(result.returncode, 0, result.stderr)
        receipt, inherited = map(json.loads, result.stdout.splitlines())
        self.assertEqual(receipt["effective_file_limit_bytes"], 2048)
        self.assertEqual(inherited, [2048, 2048])

    def test_a_stricter_soft_limit_is_never_raised_to_the_hard_limit(self) -> None:
        def restrict() -> None:
            resource.setrlimit(resource.RLIMIT_FSIZE, (2048, 8192))

        result = self.launch(4096, [sys.executable, "-c", "import resource,json; print(json.dumps(list(resource.getrlimit(resource.RLIMIT_FSIZE))))"], preexec_fn=restrict)
        self.assertEqual(result.returncode, 0, result.stderr)
        receipt, inherited = map(json.loads, result.stdout.splitlines())
        self.assertEqual(receipt["effective_file_limit_bytes"], 2048)
        self.assertEqual(inherited, [2048, 2048])

    def test_inherited_stdout_and_stderr_regular_files_are_also_capped(self) -> None:
        for descriptor in [1, 2]:
            with self.subTest(descriptor=descriptor), tempfile.TemporaryDirectory(prefix="nntmux-file-stream-limit-") as directory:
                stdout = Path(directory) / "stdout"
                stderr = Path(directory) / "stderr"
                code = f"import os; os.write({descriptor},b'x'*4096); os.write({descriptor},b'y')"
                with stdout.open("wb") as out, stderr.open("wb") as err:
                    result = self.launch(4096, [sys.executable, "-c", code], stdout=out, stderr=err)
                self.assertNotEqual(result.returncode, 0)
                self.assertLessEqual(stdout.stat().st_size, 4096)
                self.assertLessEqual(stderr.stat().st_size, 4096)
                self.assertEqual((stdout if descriptor == 1 else stderr).stat().st_size, 4096)

    def test_invalid_limits_and_nonabsolute_or_missing_commands_fail(self) -> None:
        for limit, command in [(0, [sys.executable]), (1, [sys.executable, "-c", "pass"]), (-1, [sys.executable]),
                               (8388609, [sys.executable]), (4096, ["python3"]), (4096, [])]:
            with self.subTest(limit=limit, command=command):
                result = self.launch(limit, command)
                self.assertEqual(result.returncode, 2)
                self.assertEqual(result.stdout, "")

    def test_a_regular_log_without_room_for_the_receipt_rejects_before_exec(self) -> None:
        with tempfile.TemporaryDirectory(prefix="nntmux-file-receipt-limit-") as directory:
            stdout = Path(directory) / "stdout"
            marker = Path(directory) / "executed"
            stdout.write_bytes(b"x" * 4090)
            code = "import pathlib,sys; pathlib.Path(sys.argv[1]).touch()"
            with stdout.open("ab") as out:
                result = self.launch(4096, [sys.executable, "-c", code, str(marker)], stdout=out, stderr=subprocess.PIPE)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertFalse(marker.exists())
            self.assertEqual(stdout.read_bytes(), b"x" * 4090)


if __name__ == "__main__":
    unittest.main()
