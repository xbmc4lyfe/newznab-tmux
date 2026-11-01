#!/usr/bin/env python3
"""CI gate for the rewrite. Fails if a workspace crate adds FFI or a ledger entry is invalid.
Prints what's left to port. Pass --done to also fail while any entry is still todo."""

import pathlib
import re
import sys
import tomllib

rust = pathlib.Path(__file__).resolve().parent.parent
failures = []

FFI = re.compile(r'extern\s+"C"|#\[(unsafe\()?no_mangle|\bcdylib\b')
for path in (rust / "crates").rglob("*"):
    if path.suffix in {".rs", ".toml"} and path.is_file() and "target" not in path.parts:
        for number, line in enumerate(path.read_text(errors="replace").splitlines(), 1):
            if FFI.search(line):
                failures.append(f"FFI is banned: {path.relative_to(rust)}:{number}: {line.strip()}")

totals = {}
for path in sorted((rust / "porting/ledger").glob("*.toml")):
    counts = {"todo": 0, "ported": 0, "dropped": 0}
    for entry in tomllib.loads(path.read_text()).get("entry", []):
        status = entry.get("status")
        if status not in counts:
            failures.append(f"{path.name}: {entry.get('id')}: bad status {status!r}")
            continue
        counts[status] += 1
        if status == "dropped" and not entry.get("note"):
            failures.append(f"{path.name}: {entry.get('id')}: dropped without a note")
    totals[path.stem] = counts

print(f"{'workstream':<16}{'todo':>6}{'ported':>8}{'dropped':>9}")
for name, counts in totals.items():
    print(f"{name:<16}{counts['todo']:>6}{counts['ported']:>8}{counts['dropped']:>9}")
remaining = sum(counts["todo"] for counts in totals.values())
print(f"entry points left to port: {remaining}")

if "--done" in sys.argv and remaining:
    failures.append(f"{remaining} entry points are still todo")
for failure in failures:
    print(failure, file=sys.stderr)
sys.exit(1 if failures else 0)
