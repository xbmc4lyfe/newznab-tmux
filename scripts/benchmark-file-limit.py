"""Exec a benchmark command with an inherited cap on each regular file.

The receipt is a preamble to the caller's raw console log, not an observations
record. This does not impose a wall deadline, process cleanup or aggregate quota.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import resource
import signal
import stat
import sys


MAX_FILE_BYTES = 8 * 1024 * 1024


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--file-limit-bytes", type=int, default=MAX_FILE_BYTES)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    options = parser.parse_args()
    command = options.command
    if command[:1] == ["--"]:
        command = command[1:]
    if not 4096 <= options.file_limit_bytes <= MAX_FILE_BYTES:
        parser.error("The per-file cap must be between 4096 and 8388608 bytes.")
    if not command or not Path(command[0]).is_absolute():
        parser.error("An absolute executable path and command are required.")
    try:
        executable = Path(command[0]).resolve(strict=True)
        if not executable.is_file() or not os.access(executable, os.X_OK):
            parser.error("The command must identify an executable regular file.")
        requested_command = command.copy()
        command = [str(executable), *command[1:]]
        previous = resource.getrlimit(resource.RLIMIT_FSIZE)
        finite_limits = [value for value in previous if value != resource.RLIM_INFINITY]
        effective = min([options.file_limit_bytes, *finite_limits])
        receipt = json.dumps({
            "type": "benchmark_file_limit", "schema": 1,
            "requested_file_limit_bytes": options.file_limit_bytes,
            "effective_file_limit_bytes": effective,
            "limit_scope": "per_regular_file", "aggregate_limit_enforced": False,
            "previous_limits": [None if value == resource.RLIM_INFINITY else value for value in previous],
            "command": command,
            "requested_command": requested_command,
            "launcher_file_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        }) + "\n"
        descriptor = sys.stdout.fileno()
        output = os.fstat(descriptor)
        if stat.S_ISREG(output.st_mode):
            position = max(output.st_size, os.lseek(descriptor, 0, os.SEEK_CUR))
            if position + len(receipt.encode()) > effective:
                parser.error("The regular stdout file cannot retain the complete launch receipt within its cap.")
        resource.setrlimit(resource.RLIMIT_FSIZE, (effective, effective))
        signal.signal(signal.SIGXFSZ, signal.SIG_DFL)
        print(receipt, end="", flush=True)
        os.execv(command[0], command)
    except (OSError, ValueError) as error:
        parser.exit(2, f"File-limit launch failed: {error}\n")


if __name__ == "__main__":
    main()
