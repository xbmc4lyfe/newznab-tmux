#!/usr/bin/env python3
"""Retain Linux benchmark resource endpoints and compare visible static limits."""

import argparse
import hashlib
import json
import os
import pathlib
import time


REQUIRED_STATIC_FIELDS = (
    "cpu.max", "cpu.weight", "cpuset.cpus.effective", "cpuset.mems.effective",
    "memory.max", "memory.high", "memory.swap.max", "pids.max",
)
OPTIONAL_STATIC_FIELDS = (
    "io.max", "io.weight", "cgroup.controllers", "cgroup.subtree_control",
)
STATIC_FIELDS = REQUIRED_STATIC_FIELDS + OPTIONAL_STATIC_FIELDS
DYNAMIC_FIELDS = (
    "cpu.stat", "cpu.pressure", "memory.current", "memory.peak", "memory.events",
    "memory.stat", "memory.pressure", "memory.swap.current", "pids.current",
    "pids.events", "io.stat", "io.pressure",
)
SCOPE = "Process and all namespace-visible cgroup v2 ancestors; outer host limits unknown"


def read_optional(path):
    # A missing controller file is retained explicitly. A failed read of an
    # existing file is an invalid endpoint, not an unlimited resource claim.
    return path.read_text().strip() if path.exists() else None


def capture(soft_limits, hard_limits, proc_root=pathlib.Path("/proc"),
            cgroup_root=pathlib.Path("/sys/fs/cgroup")):
    process = proc_root / "self"
    membership = (process / "cgroup").read_text()
    unified = [line.split(":", 2)[2] for line in membership.splitlines()
               if line.startswith("0::")]
    if len(unified) != 1 or not (cgroup_root / "cgroup.controllers").is_file():
        raise ValueError("Expected a readable unified cgroup v2 hierarchy")
    root = cgroup_root.resolve()
    current = (root / unified[0].lstrip("/")).resolve()
    current.relative_to(root)
    if not current.is_dir():
        raise ValueError("Cannot resolve the process cgroup inside the visible mount")
    ancestors = []
    while True:
        relative = current.relative_to(root).as_posix()
        ancestors.append({
            "cgroup": "/" if relative == "." else "/" + relative,
            "limits": {name: read_optional(current / name) for name in STATIC_FIELDS},
            "usage": {name: read_optional(current / name) for name in DYNAMIC_FIELDS},
        })
        if current == root:
            break
        current = current.parent
    process_limits = (process / "limits").read_text()
    soft = soft_limits.read_text()
    hard = hard_limits.read_text()
    if not hasattr(os, "sched_getaffinity"):
        raise ValueError("Resource capture requires Linux process CPU affinity")
    affinity = sorted(os.sched_getaffinity(0))
    if not process_limits.strip() or not soft.strip() or not hard.strip() or not affinity:
        raise ValueError("Missing process limits, shell ulimits, or CPU affinity")
    # Root controller files can be absent by kernel design. Keep existing
    # CPU/memory/pids completeness requirements. I/O controls are not exposed
    # on every kernel/cgroup: retain explicit nulls and freeze their presence
    # as well as values, without interpreting missing files as unlimited I/O.
    missing = [name for name in REQUIRED_STATIC_FIELDS
               if not any(entry["limits"][name] is not None for entry in ancestors)]
    if missing:
        raise ValueError("Unobserved visible limit dimensions: " + ", ".join(missing))
    return {
        "schema_version": 1, "valid": True, "captured_at_unix_ns": time.time_ns(),
        "scope": SCOPE, "outer_host_limits": "unknown",
        "missing_controller_files": "unknown; never interpreted as unlimited",
        "process_limits": process_limits, "cpu_affinity": affinity,
        "shell_soft_limits": soft, "shell_hard_limits": hard,
        "cgroup_membership": membership,
        "mountinfo": (process / "mountinfo").read_text(),
        "cgroup_v2_ancestors": ancestors,
    }


def static_identity(snapshot):
    if snapshot.get("schema_version") != 1 or snapshot.get("valid") is not True:
        raise ValueError("Missing or invalid resource endpoint")
    if snapshot.get("outer_host_limits") != "unknown" or snapshot.get("scope") != SCOPE:
        raise ValueError("Unsupported resource scope")
    if type(snapshot.get("captured_at_unix_ns")) is not int or snapshot["captured_at_unix_ns"] < 0:
        raise ValueError("Missing or invalid resource capture timestamp")
    for key in ("process_limits", "shell_soft_limits", "shell_hard_limits", "cgroup_membership", "mountinfo"):
        if not isinstance(snapshot.get(key), str) or not snapshot[key].strip():
            raise ValueError("Missing resource endpoint field: " + key)
    affinity = snapshot.get("cpu_affinity")
    if not isinstance(affinity, list) or not affinity or any(type(cpu) is not int or cpu < 0 for cpu in affinity):
        raise ValueError("Missing or invalid CPU affinity")
    ancestors = snapshot.get("cgroup_v2_ancestors")
    if not isinstance(ancestors, list) or not ancestors or ancestors[-1].get("cgroup") != "/":
        raise ValueError("Missing visible ancestor chain")
    for index, entry in enumerate(ancestors):
        path = entry.get("cgroup")
        if not isinstance(path, str) or not path.startswith("/") or ".." in pathlib.PurePosixPath(path).parts:
            raise ValueError("Invalid cgroup path")
        if index and str(pathlib.PurePosixPath(ancestors[index - 1]["cgroup"]).parent) != path:
            raise ValueError("Incomplete cgroup ancestor chain")
        limits = entry.get("limits")
        if not isinstance(limits, dict) or set(limits) != set(STATIC_FIELDS):
            raise ValueError("Missing cgroup limit fields")
        if any(value is not None and not isinstance(value, str) for value in limits.values()):
            raise ValueError("Invalid cgroup limit values")
        usage = entry.get("usage")
        if not isinstance(usage, dict) or set(usage) != set(DYNAMIC_FIELDS):
            raise ValueError("Missing cgroup usage endpoint")
    if any(not any(entry["limits"][name] is not None for entry in ancestors) for name in REQUIRED_STATIC_FIELDS):
        raise ValueError("Unobserved cgroup limit dimensions")
    return {
        key: snapshot[key] for key in (
            "process_limits", "cpu_affinity", "shell_soft_limits", "shell_hard_limits",
            "cgroup_membership", "mountinfo",
        )
    } | {"cgroup_v2_ancestors": [{"cgroup": entry["cgroup"], "limits": entry["limits"]}
                                  for entry in ancestors]}


def compare(before_path, after_path):
    result = {
        "schema_version": 1, "valid": False, "stable_static_limits": False,
        "outcome": "invalid-resource-endpoints", "scope": SCOPE,
        "outer_host_limits": "unknown", "before": str(before_path), "after": str(after_path),
        "dynamic_usage_compared_for_stability": False,
    }
    try:
        before = json.loads(before_path.read_text())
        after = json.loads(after_path.read_text())
        left, right = static_identity(before), static_identity(after)
        if after["captured_at_unix_ns"] < before["captured_at_unix_ns"]:
            raise ValueError("Resource endpoints are reversed")
        changes = [key for key in left if left[key] != right[key]]
        result.update(valid=True, stable_static_limits=not changes,
                      outcome="stable-visible-limits" if not changes else "changed-visible-limits",
                      changed_fields=changes)
    except (OSError, ValueError, TypeError, KeyError, AttributeError) as error:
        result["error"] = str(error)
    return result


def verified_receipt(root):
    """Validate fixed raw endpoints and their receipt; return only public digests."""
    root = pathlib.Path(root)
    paths = {name: root / f'resource-limits-{name}.json' for name in ('before', 'after', 'comparison')}
    raw = {name: path.read_bytes() for name, path in paths.items()}
    def reject_constant(value):
        raise ValueError('Nonfinite resource receipt')
    documents = {name: json.loads(value, parse_constant=reject_constant) for name, value in raw.items()}
    before, after, receipt = (documents[name] for name in ('before', 'after', 'comparison'))
    left, right = static_identity(before), static_identity(after)
    fields = dict(schema_version=1, valid=True, stable_static_limits=True, outcome='stable-visible-limits',
                  outer_host_limits='unknown', scope=SCOPE, dynamic_usage_compared_for_stability=False,
                  changed_fields=[])
    if (not isinstance(receipt, dict) or any(type(receipt.get(key)) is not type(value) or receipt.get(key) != value for key, value in fields.items())
            or left != right or after['captured_at_unix_ns'] < before['captured_at_unix_ns']
            or any(not isinstance(receipt.get(name), str) or pathlib.Path(receipt[name]).resolve() != paths[name].resolve()
                   for name in ('before', 'after'))):
        raise ValueError('Missing, invalid or changed resource limits')
    return {f'resource_limits_{name}': hashlib.sha256(value).hexdigest() for name, value in raw.items()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    snapshot = commands.add_parser("capture")
    snapshot.add_argument("--soft-limits", type=pathlib.Path, required=True)
    snapshot.add_argument("--hard-limits", type=pathlib.Path, required=True)
    comparison = commands.add_parser("compare")
    comparison.add_argument("--before", type=pathlib.Path, required=True)
    comparison.add_argument("--after", type=pathlib.Path, required=True)
    for command in (snapshot, comparison):
        command.add_argument("--output", type=pathlib.Path, required=True)
    args = parser.parse_args()
    if args.command == "capture":
        try:
            result = capture(args.soft_limits, args.hard_limits)
        except (OSError, ValueError) as error:
            result = {"schema_version": 1, "valid": False, "scope": SCOPE,
                      "outer_host_limits": "unknown", "error": str(error)}
    else:
        result = compare(args.before, args.after)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + "\n")
    return 0 if result["valid"] and result.get("stable_static_limits", True) else 1


if __name__ == "__main__":
    raise SystemExit(main())
