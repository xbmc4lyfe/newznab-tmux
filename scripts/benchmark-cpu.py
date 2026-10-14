#!/usr/bin/env python3
"""Retain raw CPU interval endpoints; compare paired snapshots, never static lscpu.

Cgroup evidence covers the mounted view only. Hidden host/namespace ancestors
remain unavailable; an unresolved mount is never interpreted as an unlimited CPU.
"""

import argparse
from datetime import datetime, timezone
import json
from pathlib import Path, PurePosixPath
import re
import sys
import time


def read_file(path):
    record = {"path": str(path)}
    try:
        record.update(status="captured", raw=path.read_text())
    except (OSError, UnicodeError) as error:
        record.update(status="unavailable", error=str(error))
    return record


def unescape(value):
    return re.sub(r"\\([0-7]{3})", lambda match: chr(int(match[1], 8)), value)


def cgroup_snapshot(proc):
    membership = read_file(proc / "self/cgroup")
    mountinfo = read_file(proc / "self/mountinfo")
    result = {"status": "unavailable", "membership": membership, "mountinfo": mountinfo,
              "above_mount_root": "unavailable",
              "scope": "Mounted view only; hidden host/namespace ancestors unavailable"}
    members = [line[3:] for line in membership.get("raw", "").splitlines()
               if line.startswith("0::")]
    if len(members) != 1 or not members[0].startswith("/") or ".." in members[0].split("/"):
        result["error"] = "No unambiguous supported cgroup v2 membership (v1 unsupported)"
        return result
    member = PurePosixPath(members[0])
    mounts = []
    for line in mountinfo.get("raw", "").splitlines():
        left, separator, right = line.partition(" - ")
        fields = left.split()
        if not separator or not right.startswith("cgroup2 ") or len(fields) < 6:
            continue
        root, mount = PurePosixPath(unescape(fields[3])), Path(unescape(fields[4]))
        if not root.is_absolute() or not mount.is_absolute() or ".." in root.parts:
            continue
        try:
            relative = member.relative_to(root)
        except ValueError:
            continue
        mounts.append((root, mount, relative))
    if not mounts:
        result["error"] = "No matching cgroup v2 mount; namespace mapping is unavailable"
        return result
    root, mount, relative = min(mounts, key=lambda item: len(item[0].parts))
    current = mount.joinpath(*relative.parts)
    ancestors = []
    while True:
        ancestors.append({"path": str(current), **{
            name: read_file(current / name) for name in ("cpu.stat", "cpu.stat.local", "cpu.max", "cpu.pressure")
        }})
        if current == mount:
            break
        current = current.parent
    result.update(status="captured", membership_path=str(member), mount_root=str(root),
                  mountpoint=str(mount), ancestors=ancestors)
    return result


def snapshot(proc=Path("/proc")):
    captured_at = datetime.now(timezone.utc).isoformat()
    monotonic_ns = time.monotonic_ns()
    records = {name: read_file(proc / path) for name, path in (
        ("stat", "stat"), ("pressure_cpu", "pressure/cpu"), ("loadavg", "loadavg"))}
    cpu = next((line.split()[1:] for line in records["stat"].get("raw", "").splitlines()
                if line.startswith("cpu ")), [])
    usable = len(cpu) >= 4 and all(re.fullmatch(r"[0-9]+", field) for field in cpu)
    if not usable:
        records["stat"]["validation_error"] = "No usable aggregate CPU tick counters"
    return {"schema_version": 1, "captured_at": captured_at, "monotonic_ns": monotonic_ns,
            "os_cpu_usable": bool(usable), "os": records, "cgroup_v2": cgroup_snapshot(proc)}


def main(argv=None, proc=Path("/proc")):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args(argv)
    data = snapshot(proc)
    try:
        with args.output.open("x") as output:
            json.dump(data, output, indent=2)
            output.write("\n")
    except OSError as error:
        print(f"CPU evidence output failed: {error}", file=sys.stderr)
        return 2
    if not data["os_cpu_usable"]:
        print("CPU evidence retained but no usable OS CPU counters", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
