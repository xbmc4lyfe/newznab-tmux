#!/usr/bin/env python3
"""Read a private, literal environment file and exec the standalone worker."""
import argparse
import os
from pathlib import Path
import re
import shlex
import stat
import sys

ALLOWED_KEYS = frozenset({
    "DATABASE_URL", "FINGERPRINT_WORKER_ID", "FINGERPRINT_CONCURRENCY",
    "NNTP_SERVER", "NNTP_PORT", "NNTP_SSL", "NNTP_USERNAME", "NNTP_PASSWORD",
    "NNTP_SERVER_A", "NNTP_PORT_A", "NNTP_SSL_A", "NNTP_USERNAME_A", "NNTP_PASSWORD_A",
    "FINGERPRINT_ALTERNATE_PERCENT", "NNTP_TIMEOUT",
})


def read_config(path):
    """Shell quoting is accepted as data. No interpolation, sourcing, shell or eval."""
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid():
            raise ValueError("Worker config must be a regular file owned by this user")
        if stat.S_IMODE(info.st_mode) != 0o600:
            raise ValueError("Worker config permissions must be 0600")
        if info.st_size > 65536:
            raise ValueError("Worker config exceeds 64 KiB")
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = None
            raw = stream.read(65537)
        values = {}
        for number, line in enumerate(raw.splitlines(), start=1):
            try:
                words = shlex.split(line, comments=True, posix=True)
            except ValueError:
                raise ValueError(f"Malformed shell quoting on config line {number}") from None
            if not words:
                continue
            if len(words) != 1 or "=" not in words[0]:
                raise ValueError(f"Expected one KEY=value assignment on config line {number}")
            key, value = words[0].split("=", 1)
            if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key) or key not in ALLOWED_KEYS:
                raise ValueError(f"Unsupported worker setting on config line {number}")
            if key in values:
                raise ValueError(f"Duplicate worker setting on config line {number}")
            if "\x00" in value:
                raise ValueError(f"Invalid worker setting on config line {number}")
            values[key] = value
        if not values.get("DATABASE_URL") or not values.get("NNTP_SERVER", "").strip():
            raise ValueError("DATABASE_URL and NNTP_SERVER are required")
        worker_id = values.get("FINGERPRINT_WORKER_ID", "")
        if worker_id and (len(worker_id.encode()) > 120 or any(ord(c) < 32 for c in worker_id)):
            raise ValueError("FINGERPRINT_WORKER_ID must be at most 120 bytes without control characters")
        return values
    finally:
        if fd is not None:
            os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--binary", required=True, type=Path)
    parser.add_argument("--config", required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    binary = args.binary.absolute()
    info = binary.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or not os.access(binary, os.X_OK):
        raise ValueError("Worker binary must be an executable regular file owned by this user")
    values = read_config(args.config)
    if not values.get("FINGERPRINT_WORKER_ID"):
        raise ValueError("Installed worker config has no unique FINGERPRINT_WORKER_ID")
    environment = os.environ.copy()
    # Worker settings come only from the private file, never an inherited shell's stale credentials.
    for key in ALLOWED_KEYS:
        environment.pop(key, None)
    environment.update(values)
    os.execve(str(binary), [str(binary)], environment)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError) as error:
        print(f"Worker runner refused to start: {error}", file=sys.stderr)
        sys.exit(1)
