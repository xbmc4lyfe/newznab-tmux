"""Build the frozen C++ date reference and retain an executed-build receipt."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import time


FLAGS = ["-std=c++20", "-O3", "-DNDEBUG", "-fPIC", "-shared", "-Wall", "-Wextra", "-Werror"]
FILE_LIMIT = 8388608
SOURCE = "scripts/benchmark-date-cpp.cpp"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(65536), b""):
            digest.update(block)
    return digest.hexdigest()


def utc() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_json(path: Path, value: dict) -> None:
    with path.open("x", encoding="utf-8") as stream:
        json.dump(value, stream, indent=2, sort_keys=True)
        stream.write("\n")


def source_hashes(root: Path, sources: dict) -> dict:
    if not isinstance(sources, dict) or not sources:
        raise ValueError("A nonempty relative source SHA-256 map is required.")
    result = {}
    for relative, expected in sources.items():
        if (not isinstance(relative, str) or not isinstance(expected, str)
                or len(expected) != 64 or any(c not in "0123456789abcdef" for c in expected)):
            raise ValueError("Invalid source inventory entry.")
        path = Path(relative)
        if path.is_absolute() or ".." in path.parts:
            raise ValueError("Source inventory paths must stay inside the source directory.")
        candidate = root / path
        if not candidate.resolve(strict=True).is_relative_to(root) or not candidate.is_file():
            raise ValueError("Source inventory escaped the source directory or is not regular.")
        result[relative] = sha256(candidate)
    return result


def resolve_tool(value, default: str) -> Path:
    found = value if value is not None else shutil.which(default)
    if found is None:
        raise FileNotFoundError(f"Required executable not found: {default}")
    path = Path(found).resolve(strict=True)
    if not path.is_file() or not os.access(path, os.X_OK):
        raise ValueError(f"Executable is not a regular executable file: {path}")
    return path


def execute_default(command: list[str], stdout: Path, stderr: Path) -> int:
    with stdout.open("xb") as out, stderr.open("xb") as err:
        return subprocess.run(command, stdout=out, stderr=err, check=False).returncode


def compiler_version(path: Path) -> str:
    # The cap wrapper's JSON preamble remains in the raw log, not the version.
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    if lines:
        try:
            preamble = json.loads(lines[0])
            if isinstance(preamble, dict) and preamble.get("type") == "benchmark_file_limit":
                lines = lines[1:]
        except ValueError:
            pass
    return "\n".join(lines).strip()


def valid_elf(path: Path, fixture: bool) -> bool:
    with path.open("rb") as stream:
        header = stream.read(64)
    if header[:4] != b"\x7fELF":
        return False
    if fixture:
        return True
    if len(header) < 52 or header[4] not in (1, 2) or header[5] not in (1, 2) or header[6] != 1:
        return False
    byteorder = "little" if header[5] == 1 else "big"
    size = 52 if header[4] == 1 else 64
    size_offset = 40 if header[4] == 1 else 52
    return (len(header) >= size and int.from_bytes(header[16:18], byteorder) == 3
            and int.from_bytes(header[20:24], byteorder) == 1
            and int.from_bytes(header[size_offset:size_offset + 2], byteorder) == size)


def build_reference(root, evidence, sources, *, compiler=None, timeout_tool=None, execute=None) -> dict:
    root = Path(root).resolve(strict=True)
    evidence = Path(evidence).absolute()
    # Ownership begins here; never overwrite or inspect another run's directory.
    evidence.mkdir()
    fixture = any(value is not None for value in (compiler, timeout_tool, execute))
    started = time.monotonic()
    receipt = {"schema": 1, "test_fixture": fixture, "started_utc": utc(),
               "build_status": "launch_failed", "baseline_source_sha256": sources,
               "file_limit_bytes": FILE_LIMIT, "limit_scope": "per_regular_file",
               "aggregate_limit_enforced": False, "deadline_seconds": 120,
               "kill_after_seconds": 5, "descendant_absence_verified": False,
               "commands": []}
    tools = {}
    before = None
    output = evidence / "libdate-reference.so"
    executor = execute or execute_default
    try:
        if not fixture and not sys.platform.startswith("linux"):
            receipt["build_status"] = "unsupported_platform"
            raise ValueError("Production C++ reference builds require Linux.")
        before = source_hashes(root, sources)
        receipt["source_sha256_before"] = before
        if before != sources:
            receipt["build_status"] = "source_changed"
            raise ValueError("Declared source hashes do not match the files.")
        source = root / SOURCE
        if SOURCE not in sources:
            raise ValueError("Source inventory must include the C++ reference source.")
        compiler_path = resolve_tool(compiler, "c++")
        timeout_path = resolve_tool(timeout_tool, "timeout")
        helper = Path(__file__).resolve(strict=True)
        wrapper = helper.with_name("benchmark-file-limit.py").resolve(strict=True)
        python = Path(sys.executable).resolve(strict=True)
        candidates = {"compiler": compiler_path, "timeout": timeout_path,
                      "build_helper": helper, "file_limit_helper": wrapper, "python": python}
        receipt["tools"] = {name: {"path": str(path), "sha256": sha256(path)}
                            for name, path in candidates.items()}
        tools = candidates
        receipt["compiler_command"] = [str(compiler_path), *FLAGS, str(source), "-o", str(output)]

        def run_phase(name: str, command: list[str]) -> int:
            launcher = [str(python), "-B", str(wrapper), "--file-limit-bytes", str(FILE_LIMIT), "--",
                        str(timeout_path), "--signal=TERM", "--kill-after=5s", "120s", *command]
            out, err = evidence / f"{name}.stdout.log", evidence / f"{name}.stderr.log"
            phase = {"phase": name, "command": command, "launcher_command": launcher,
                     "started_utc": utc()}
            receipt["commands"].append(phase)
            clock = time.monotonic()
            try:
                code = executor(launcher, out, err)
                phase["exit"] = code
                return code
            finally:
                phase["finished_utc"] = utc()
                phase["elapsed_seconds"] = time.monotonic() - clock
                phase["logs"] = {}
                for log in (out, err):
                    if log.exists() and not log.is_symlink() and stat.S_ISREG(log.stat().st_mode):
                        size = log.stat().st_size
                        phase["logs"][log.name] = {"bytes": size}
                        if size <= FILE_LIMIT:
                            phase["logs"][log.name]["sha256"] = sha256(log)
                        else:
                            raise ValueError("Compiler log exceeded the per-file cap.")

        receipt["version_exit"] = run_phase("version", [str(compiler_path), "--version"])
        if receipt["version_exit"] != 0:
            receipt["build_status"] = "compiler_version_failed"
        else:
            receipt["compiler_version"] = compiler_version(evidence / "version.stdout.log")
            if not receipt["compiler_version"]:
                raise ValueError("Compiler version output was empty.")
            if output.exists() or output.is_symlink():
                raise ValueError("Compiler output already exists before invocation.")
            receipt["compiler_exit"] = run_phase("compile", receipt["compiler_command"])
            if receipt["compiler_exit"] != 0:
                receipt["build_status"] = "compiler_failed"
            elif not output.exists() and not output.is_symlink():
                receipt["build_status"] = "output_missing"
            elif output.is_symlink() or not stat.S_ISREG(output.lstat().st_mode):
                receipt["build_status"] = "output_not_regular"
            elif output.stat().st_size == 0 or output.stat().st_size > FILE_LIMIT or not valid_elf(output, fixture):
                receipt["build_status"] = "output_invalid"
            else:
                receipt["build_status"] = "test_fixture_completed" if fixture else "completed"
    except Exception as error:
        receipt["error"] = f"{type(error).__name__}: {error}"
    finally:
        if before is not None:
            try:
                after = source_hashes(root, sources)
                receipt["source_sha256_after"] = after
                if after != before or after != sources:
                    receipt["build_status"] = "source_changed"
            except Exception as error:
                receipt["build_status"] = "source_changed"
                receipt["source_check_error"] = f"{type(error).__name__}: {error}"
        for name, path in tools.items():
            try:
                actual = sha256(path)
                receipt["tools"][name]["sha256_after"] = actual
                if actual != receipt["tools"][name]["sha256"]:
                    receipt["build_status"] = "tool_changed"
            except Exception as error:
                receipt["build_status"] = "tool_changed"
                receipt["tool_check_error"] = f"{type(error).__name__}: {error}"
        if receipt["build_status"] in ("completed", "test_fixture_completed"):
            try:
                receipt["library_sha256"] = sha256(output)
            except OSError as error:
                receipt["build_status"] = "output_invalid"
                receipt["error"] = str(error)
        receipt["finished_utc"] = utc()
        receipt["elapsed_seconds"] = time.monotonic() - started
        write_json(evidence / "build-receipt.json", receipt)
    if receipt["build_status"] == "completed" and not fixture:
        write_json(evidence / "baseline-manifest.json", {
            "baseline_kind": "cpp_nzbget_restricted_date_complete_metadata",
            "baseline_library_sha256": receipt["library_sha256"],
            "baseline_source_sha256": sources,
            "compiler": {**receipt["tools"]["compiler"], "version": receipt["compiler_version"], "flags": FLAGS},
            "upstream": {"commit": "8f2f409d4e263ecac3313404ed4685e7dca3d9ec",
                         "source_sha256": "14a1c4c82c6c551806b142b3f9ac177a39af48dbaa92bceb763f98a21f0390d8"},
            "build_receipt_sha256": sha256(evidence / "build-receipt.json"),
        })
    return receipt


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-dir", required=True, type=Path)
    parser.add_argument("--source-manifest", required=True, type=Path,
                        help="JSON relative-path/SHA-256 map from remainingBenchmarkSources().")
    parser.add_argument("--output-dir", required=True, type=Path)
    options = parser.parse_args()
    try:
        sources = json.loads(options.source_manifest.read_text(encoding="utf-8"))
        receipt = build_reference(options.source_dir, options.output_dir, sources)
    except (OSError, ValueError) as error:
        parser.exit(2, f"Build setup failed: {error}\n")
    print(json.dumps(receipt))
    raise SystemExit(0 if receipt["build_status"] == "completed" else 1)


if __name__ == "__main__":
    main()
